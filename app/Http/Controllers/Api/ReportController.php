<?php

namespace App\Http\Controllers\Api;

use App\Models\Report;
use App\Models\Shop;
use App\Models\ActivityLog;
use App\Models\Operation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends ApiController
{
    public function index(Request $request, Shop $shop)
    {
        $this->ensureAdmin($this->authOrFail($request), $shop);
        $period = $request->validate(['period' => 'nullable|in:daily,weekly,monthly'])['period'] ?? 'daily';
        $validatedDates = $shop->dailyClosures()->where('status', 'validated')->pluck('date');
        $reports = $shop->reports()->whereIn('date', $validatedDates)->orderByDesc('date')->get();
        if ($period !== 'daily') {
            $reports = $reports
                ->groupBy(fn (Report $report) => $period === 'weekly'
                    ? $report->date->copy()->startOfWeek()->toDateString()
                    : $report->date->format('Y-m').'-01')
                ->map(function ($items, $date) use ($shop, $period) {
                    return [
                        'id' => 0,
                        'shop_id' => $shop->id,
                        'date' => $date,
                        'total_in' => round($items->sum('total_in'), 2),
                        'total_out' => round($items->sum('total_out'), 2),
                        'cash_balance' => round($items->sum('cash_balance'), 2),
                        'note' => $period === 'weekly' ? 'Synthèse hebdomadaire validée' : 'Synthèse mensuelle validée',
                        'report_ids' => $items->pluck('id')->values(),
                    ];
                })->values();
        }
        return $this->resource(['reports' => $reports]);
    }

    public function show(Request $request, Shop $shop, Report $report)
    {
        $this->ensureAdmin($this->authOrFail($request), $shop);
        abort_unless($report->shop_id === $shop->id, 404);
        $closure = $shop->dailyClosures()->with('validator:id,name')->whereDate('date', $report->date)->where('status', 'validated')->firstOrFail();
        $operations = $shop->operations()->whereDate('occurred_at', $report->date)->get();
        $other = fn ($type, $label, $direction) => ['key' => $type, 'label' => $label, 'entries' => $direction === 'in' ? round((float) $operations->where('type', $type)->sum('amount'), 2) : 0, 'outputs' => $direction === 'out' ? round((float) $operations->where('type', $type)->sum('amount'), 2) : 0];
        $channel = fn ($key, $label) => ['key' => $key, 'label' => $label,
            'entries' => round((float) $operations->where('service', $key)->where('direction', 'in')->sum('amount'), 2),
            'outputs' => round((float) $operations->where('service', $key)->where('direction', 'out')->sum('amount'), 2)];

        return $this->resource([
            'report' => $report,
            'closure' => $closure,
            'channels' => [
                $other('expense', 'Dépenses', 'out'),
                $other('debt', 'Dettes accordées', 'out'),
                $other('debt_repayment', 'Remboursements de dettes', 'in'),
                $other('virtual_credit_purchase', 'Achats crédit / virtuel', 'out'),
                $channel('moov_credit', 'Moov crédit'), $channel('flooz', 'Flooz'),
                $channel('momo', 'MoMo'), $channel('mtn_credit', 'MTN crédit'),
                $channel('celtiis', 'Celtiis'),
            ],
            'operation_details' => $operations->map(fn ($operation) => [
                'service' => $operation->service, 'direction' => $operation->direction,
                'service_label' => match ($operation->service) {
                    'momo' => 'MoMo', 'flooz' => 'Flooz', 'mtn_credit' => 'MTN crédit',
                    'moov_credit' => 'Moov crédit', 'celtiis' => 'Celtiis',
                    default => match ($operation->type) {
                        'expense' => 'Dépense', 'debt' => 'Dette',
                        'debt_repayment' => 'Remboursement',
                        'virtual_credit_purchase' => 'Achat crédit / virtuel',
                        default => 'Autres',
                    },
                },
                'type' => $operation->type, 'amount' => $operation->amount,
                'phone' => $operation->phone, 'network' => $operation->network,
                'description' => $operation->description,
                'time' => $operation->occurred_at?->format('H:i'),
            ])->values(),
            'expenses' => $closure->expenses ?? [],
            'debts' => $closure->debts ?? [],
            'opening_balance' => round((float) $shop->operations()->where('occurred_at', '<', $report->date->copy()->startOfDay())->selectRaw("SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) as balance")->value('balance'), 2),
        ]);
    }

    public function export(Request $request, Shop $shop)
    {
        $this->ensureAdmin($this->authOrFail($request), $shop);
        $data = $request->validate(['format' => 'nullable|in:xls,pdf', 'report_id' => 'nullable|integer']);
        $format = $data['format'] ?? 'xls';
        $closures = $shop->dailyClosures()->where('status', 'validated')
            ->when(isset($data['report_id']), function ($query) use ($data, $shop) {
                $report = $shop->reports()->findOrFail($data['report_id']);
                $query->whereDate('date', $report->date);
            })->with('validator:id,name')->orderByDesc('date')->get();
        abort_if($closures->isEmpty(), 422, 'Aucun rapport validé à exporter.');
        $escape = fn ($value) => htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $headers = ['Date', 'Caisse ouverture', 'Dépenses', 'Dettes', 'Achats crédit/virtuel', 'Remboursements', 'Moov crédit', 'Flooz', 'MoMo', 'MTN crédit', 'Celtiis', 'Encaissements', 'Décaissements', 'Total du jour', 'Caisse après journée', 'Validé par', 'Détails des opérations'];
        if ($format === 'pdf') {
            $lines = ['POINT DE VENTE | '.$shop->name];
            foreach ($closures as $closure) {
                $report = Report::where('shop_id', $shop->id)->whereDate('date', $closure->date)->first();
                $opening = $this->openingBalance($shop->id, $closure->date);
                $lines[] = 'RAPPORT | '.$closure->date->format('d/m/Y').' | Valide par '.($closure->validator?->name ?? 'Non renseigne');
                $lines[] = 'SITUATION DE LA CAISSE | MONTANT';
                $lines[] = 'Caisse a l ouverture | '.number_format($opening, 0, ',', ' ').' FCFA';
                $lines[] = 'Total encaissements | '.number_format($report?->total_in ?? 0, 0, ',', ' ').' FCFA';
                $lines[] = 'Total decaissements | '.number_format($report?->total_out ?? 0, 0, ',', ' ').' FCFA';
                $lines[] = 'Total du jour | '.number_format($report?->cash_balance ?? 0, 0, ',', ' ').' FCFA';
                $lines[] = 'Caisse apres la journee | '.number_format($opening + ($report?->cash_balance ?? 0), 0, ',', ' ').' FCFA';
                $lines[] = 'SERVICE | ENCAISSEMENTS | DECAISSEMENTS | SOLDE';
                foreach ($this->serviceRows($shop->id, $closure->date) as $row) {
                    $lines[] = $row['label'].' | '.number_format($row['entries'], 0, ',', ' ').' | '.number_format($row['outputs'], 0, ',', ' ').' | '.number_format($row['entries'] - $row['outputs'], 0, ',', ' ');
                }
                $lines[] = 'HEURE | SERVICE | SENS | MOTIF | NUMERO | MONTANT';
                foreach (Operation::where('shop_id', $shop->id)->whereDate('occurred_at', $closure->date)->orderBy('occurred_at')->get() as $operation) {
                    $lines[] = sprintf('%s | %s | %s | %s | %s | %s%.0f', $operation->occurred_at->format('H:i'), $this->serviceLabel($operation), $operation->direction === 'in' ? 'Encaissement' : 'Decaissement', $operation->description ?: 'Sans motif', $operation->phone ?: '-', $operation->direction === 'out' ? '-' : '+', $operation->amount);
                }
            }
            $pdf = $this->simplePdf($lines);
            return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Length' => strlen($pdf), 'Content-Disposition' => 'attachment; filename="rapports-'.$shop->id.'.pdf"']);
        }
        $xml = $this->excelXml($shop, $closures);
        return response($xml, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="rapports-'.$shop->id.'.xls"',
        ]);
    }

    public function destroy(Request $request, Shop $shop, Report $report)
    {
        $user = $this->authOrFail($request);
        $this->ensureAdmin($user, $shop);
        abort_unless($report->shop_id === $shop->id, 404);
        DB::transaction(function () use ($shop, $report, $user, $request) {
            $shop->dailyClosures()->whereDate('date', $report->date)->update(['status' => 'open', 'validated_by' => null, 'submitted_at' => null]);
            $details = $report->toArray();
            $id = $report->id;
            $report->delete();
            ActivityLog::create(['user_id' => $user->id, 'action' => 'report_deleted', 'subject_type' => Report::class, 'subject_id' => $id, 'details' => $details, 'ip_address' => $request->ip()]);
        });
        return response()->noContent();
    }

    public function destroyAll(Request $request, Shop $shop)
    {
        $user = $this->authOrFail($request);
        $this->ensureAdmin($user, $shop);
        $count = $shop->reports()->count();
        DB::transaction(function () use ($shop, $user, $request, $count) {
            $shop->dailyClosures()->where('status', 'validated')->update(['status' => 'open', 'validated_by' => null, 'submitted_at' => null]);
            $shop->reports()->delete();
            ActivityLog::create(['user_id' => $user->id, 'action' => 'reports_cleared', 'subject_type' => Shop::class, 'subject_id' => $shop->id, 'details' => ['count' => $count], 'ip_address' => $request->ip()]);
        });
        return $this->resource(['message' => "$count rapport(s) supprimé(s)."]);
    }

    public function destroySelection(Request $request, Shop $shop)
    {
        $user = $this->authOrFail($request);
        $this->ensureAdmin($user, $shop);
        $ids = $request->validate(['report_ids' => 'required|array|min:1', 'report_ids.*' => 'integer'])['report_ids'];
        $reports = $shop->reports()->whereIn('id', $ids)->get();
        DB::transaction(function () use ($shop, $reports, $user, $request) {
            foreach ($reports as $report) {
                $shop->dailyClosures()->whereDate('date', $report->date)->update(['status' => 'open', 'validated_by' => null, 'submitted_at' => null]);
            }
            $shop->reports()->whereIn('id', $reports->pluck('id'))->delete();
            ActivityLog::create(['user_id' => $user->id, 'action' => 'reports_deleted', 'subject_type' => Shop::class, 'subject_id' => $shop->id, 'details' => ['count' => $reports->count()], 'ip_address' => $request->ip()]);
        });
        return $this->resource(['message' => $reports->count().' rapport(s) supprimé(s).']);
    }

    private function row($closure): array
    {
        $operations = Operation::where('shop_id', $closure->shop_id)->whereDate('occurred_at', $closure->date)->get();
        $report = Report::where('shop_id', $closure->shop_id)->whereDate('date', $closure->date)->first();
        $opening = $this->openingBalance($closure->shop_id, $closure->date);
        return [
            $closure->date->format('d/m/Y'), $opening, $operations->where('type', 'expense')->sum('amount'),
            $operations->where('type', 'debt')->sum('amount'), $operations->where('type', 'virtual_credit_purchase')->sum('amount'),
            $operations->where('type', 'debt_repayment')->sum('amount'), $closure->moov_credit, $closure->flooz,
            $closure->momo, $closure->mtn_credit, $closure->celtiis, $report?->total_in ?? 0,
            $report?->total_out ?? 0, $report?->cash_balance ?? 0, $opening + ($report?->cash_balance ?? 0), $closure->validator?->name ?? '',
            $operations->map(fn ($operation) => sprintf('%s %.0f - %s%s', $operation->direction === 'out' ? '-' : '+', $operation->amount, $operation->description, $operation->phone ? ' ('.$operation->phone.')' : ''))->implode(' | '),
        ];
    }

    private function openingBalance(int $shopId, $date): float
    {
        return round((float) Operation::where('shop_id', $shopId)->where('occurred_at', '<', $date->copy()->startOfDay())
            ->selectRaw("SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) as balance")->value('balance'), 2);
    }

    private function serviceRows(int $shopId, $date): array
    {
        $operations = Operation::where('shop_id', $shopId)->whereDate('occurred_at', $date)->get();
        $definitions = ['expense' => 'Dépenses', 'debt' => 'Dettes', 'debt_repayment' => 'Remboursements', 'virtual_credit_purchase' => 'Achat crédit/virtuel', 'moov_credit' => 'Moov crédit', 'flooz' => 'Flooz', 'momo' => 'MoMo', 'mtn_credit' => 'MTN crédit', 'celtiis' => 'Celtiis'];
        return collect($definitions)->map(function ($label, $key) use ($operations) {
            $items = in_array($key, ['expense', 'debt', 'debt_repayment', 'virtual_credit_purchase'], true) ? $operations->where('type', $key) : $operations->where('service', $key);
            return ['label' => $label, 'entries' => (float) $items->where('direction', 'in')->sum('amount'), 'outputs' => (float) $items->where('direction', 'out')->sum('amount')];
        })->values()->all();
    }

    private function serviceLabel(Operation $operation): string
    {
        return match ($operation->service) {
            'momo' => 'MoMo', 'flooz' => 'Flooz', 'mtn_credit' => 'MTN credit',
            'moov_credit' => 'Moov credit', 'celtiis' => 'Celtiis',
            default => match ($operation->type) {
                'expense' => 'Depense', 'debt' => 'Dette', 'debt_repayment' => 'Remboursement',
                'virtual_credit_purchase' => 'Achat credit', default => 'Autres',
            },
        };
    }

    private function reportHtml(Shop $shop, $closures): string
    {
        $pages = '';
        foreach ($closures as $closure) {
            $report = Report::where('shop_id', $shop->id)->whereDate('date', $closure->date)->first();
            $operations = Operation::where('shop_id', $shop->id)->whereDate('occurred_at', $closure->date)->orderBy('occurred_at')->get();
            $opening = $this->openingBalance($shop->id, $closure->date);
            $serviceRows = '';
            foreach ($this->serviceRows($shop->id, $closure->date) as $row) {
                $serviceRows .= '<tr><td>'.e($row['label']).'</td><td class="in">'.number_format($row['entries'], 0, ',', ' ').'</td><td class="out">'.number_format($row['outputs'], 0, ',', ' ').'</td><td>'.number_format($row['entries'] - $row['outputs'], 0, ',', ' ').'</td></tr>';
            }
            $operationRows = '';
            foreach ($operations as $operation) {
                $sign = $operation->direction === 'out' ? '-' : '+';
                $class = $operation->direction === 'out' ? 'out' : 'in';
                $operationRows .= '<tr><td>'.$operation->occurred_at->format('H:i').'</td><td>'.e($this->serviceLabel($operation)).'</td><td>'.e($operation->direction === 'in' ? 'Encaissement' : 'Décaissement').'</td><td>'.e($operation->description).'</td><td>'.e($operation->phone ?: '—').'</td><td class="'.$class.'">'.$sign.number_format($operation->amount, 0, ',', ' ').'</td></tr>';
            }
            $total = (float) ($report?->cash_balance ?? 0);
            $pages .= '<section class="report"><header><div class="brand">ENAGNON LEADER</div><div class="subtitle">Rapport journalier du '.$closure->date->format('d/m/Y').'</div></header>
                <div class="meta"><div><span>Point de vente</span><strong>'.e($shop->name).'</strong></div><div><span>Validé par</span><strong>'.e($closure->validator?->name ?? 'Non renseigné').'</strong></div><div><span>Validation</span><strong>'.e(optional($closure->submitted_at)->format('d/m/Y H:i') ?? '—').'</strong></div></div>
                <h2>Situation de la caisse</h2><div class="cards"><div><span>Caisse à l’ouverture</span><b>'.number_format($opening, 0, ',', ' ').' FCFA</b></div><div><span>Total encaissements</span><b class="in">'.number_format($report?->total_in ?? 0, 0, ',', ' ').' FCFA</b></div><div><span>Total décaissements</span><b class="out">'.number_format($report?->total_out ?? 0, 0, ',', ' ').' FCFA</b></div><div><span>Total du jour</span><b class="'.($total < 0 ? 'out' : 'in').'">'.($total >= 0 ? '+' : '').number_format($total, 0, ',', ' ').' FCFA</b></div><div><span>Caisse après journée</span><b>'.number_format($opening + $total, 0, ',', ' ').' FCFA</b></div></div>
                <h2>Totaux par service</h2><table><thead><tr><th>Service</th><th>Encaissements</th><th>Décaissements</th><th>Solde</th></tr></thead><tbody>'.$serviceRows.'</tbody></table>
                <h2>Opérations effectuées</h2><table class="ops"><thead><tr><th>Heure</th><th>Service</th><th>Sens</th><th>Motif</th><th>Numéro</th><th>Montant</th></tr></thead><tbody>'.$operationRows.'</tbody></table>
                <footer>ENAGNON LEADER • Document généré le '.now()->format('d/m/Y à H:i').'</footer></section>';
        }
        return '<!doctype html><html><head><meta charset="UTF-8"><style>@page{margin:24px}*{box-sizing:border-box}body{font-family:DejaVu Sans,sans-serif;color:#17213a;font-size:10px}.report{page-break-after:always}.report:last-child{page-break-after:auto}header{background:#064f82;color:#fff;padding:20px 24px;border-bottom:8px solid #159586}.brand{font-size:25px;font-weight:800;letter-spacing:1px}.subtitle{font-size:13px;margin-top:5px}.meta{display:table;width:100%;background:#eef4fb;padding:12px;margin:14px 0}.meta>div{display:table-cell;width:33%;padding:4px 10px}.meta span,.cards span{display:block;color:#61708b;font-size:8px;text-transform:uppercase;margin-bottom:4px}.meta strong{font-size:10px}h2{font-size:13px;color:#064f82;margin:16px 0 7px;border-left:5px solid #159586;padding-left:8px}.cards{display:table;width:100%;border-spacing:6px}.cards>div{display:table-cell;background:#f5f8fc;border:1px solid #d6dfeb;padding:9px;text-align:center}.cards b{font-size:11px}table{width:100%;border-collapse:collapse;margin-bottom:12px;page-break-inside:auto}thead{display:table-header-group}tr{page-break-inside:avoid}th{background:#159586;color:#fff;padding:7px 5px;border:1px solid #0c756a;text-align:center}td{padding:6px 5px;border:1px solid #cbd4df}tbody tr:nth-child(even){background:#f3f6fa}td:not(:first-child){text-align:right}.ops{font-size:8px}.ops td:nth-child(4){text-align:left}.in{color:#087c66;font-weight:700}.out{color:#c43f4f;font-weight:700}footer{margin-top:15px;padding-top:8px;border-top:1px solid #cbd4df;color:#738096;text-align:center;font-size:8px}</style></head><body>'.$pages.'</body></html>';
    }

    private function excelXml(Shop $shop, $closures): string
    {
        $escape = fn ($value) => htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xml = '<?xml version="1.0" encoding="UTF-8"?><?mso-application progid="Excel.Sheet"?><Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Styles><Style ss:ID="Header"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#159586" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center"/></Style><Style ss:ID="Title"><Font ss:Bold="1" ss:Size="16" ss:Color="#064F82"/></Style><Style ss:ID="Money"><NumberFormat ss:Format="# ##0 \&quot;FCFA\&quot;"/></Style></Styles>';
        foreach ($closures as $closure) {
            $report = Report::where('shop_id', $shop->id)->whereDate('date', $closure->date)->first();
            $opening = $this->openingBalance($shop->id, $closure->date);
            $name = 'Synthese-'.$closure->date->format('dmY');
            $xml .= '<Worksheet ss:Name="'.$name.'"><Table><Column ss:Width="180"/><Column ss:Width="120"/><Row><Cell ss:StyleID="Title"><Data ss:Type="String">ENAGNON LEADER</Data></Cell></Row>';
            foreach ([['Point de vente', $shop->name], ['Date', $closure->date->format('d/m/Y')], ['Validé par', $closure->validator?->name ?? 'Non renseigné'], ['Caisse à l’ouverture', $opening], ['Total encaissements', $report?->total_in ?? 0], ['Total décaissements', $report?->total_out ?? 0], ['Total du jour', $report?->cash_balance ?? 0], ['Caisse après journée', $opening + ($report?->cash_balance ?? 0)]] as $row) {
                $xml .= '<Row><Cell><Data ss:Type="String">'.$escape($row[0]).'</Data></Cell><Cell'.(is_numeric($row[1]) ? ' ss:StyleID="Money"' : '').'><Data ss:Type="'.(is_numeric($row[1]) ? 'Number' : 'String').'">'.$escape($row[1]).'</Data></Cell></Row>';
            }
            $xml .= '</Table></Worksheet><Worksheet ss:Name="Services-'.$closure->date->format('dmY').'"><Table><Row>';
            foreach (['Service','Encaissements','Décaissements','Solde'] as $header) $xml .= '<Cell ss:StyleID="Header"><Data ss:Type="String">'.$escape($header).'</Data></Cell>';
            $xml .= '</Row>';
            foreach ($this->serviceRows($shop->id, $closure->date) as $row) $xml .= '<Row><Cell><Data ss:Type="String">'.$escape($row['label']).'</Data></Cell><Cell ss:StyleID="Money"><Data ss:Type="Number">'.$row['entries'].'</Data></Cell><Cell ss:StyleID="Money"><Data ss:Type="Number">'.$row['outputs'].'</Data></Cell><Cell ss:StyleID="Money"><Data ss:Type="Number">'.($row['entries']-$row['outputs']).'</Data></Cell></Row>';
            $xml .= '</Table></Worksheet><Worksheet ss:Name="Operations-'.$closure->date->format('dmY').'"><Table><Row>';
            foreach (['Heure','Service','Sens','Motif','Numéro','Montant FCFA'] as $header) $xml .= '<Cell ss:StyleID="Header"><Data ss:Type="String">'.$escape($header).'</Data></Cell>';
            $xml .= '</Row>';
            foreach (Operation::where('shop_id',$shop->id)->whereDate('occurred_at',$closure->date)->orderBy('occurred_at')->get() as $operation) $xml .= '<Row><Cell><Data ss:Type="String">'.$operation->occurred_at->format('H:i').'</Data></Cell><Cell><Data ss:Type="String">'.$escape($this->serviceLabel($operation)).'</Data></Cell><Cell><Data ss:Type="String">'.($operation->direction==='in'?'Encaissement':'Décaissement').'</Data></Cell><Cell><Data ss:Type="String">'.$escape($operation->description).'</Data></Cell><Cell><Data ss:Type="String">'.$escape($operation->phone).'</Data></Cell><Cell ss:StyleID="Money"><Data ss:Type="Number">'.($operation->direction==='out'?-$operation->amount:$operation->amount).'</Data></Cell></Row>';
            $xml .= '</Table></Worksheet>';
        }
        return $xml.'</Workbook>';
    }

    private function simplePdf(array $lines): string
    {
        $text = "0.02 0.31 0.54 rg 25 760 545 62 re f 1 1 1 rg BT /F1 18 Tf 42 796 Td (ENAGNON LEADER) Tj 0 -24 Td /F1 11 Tf (Rapport detaille des operations) Tj ET 0.08 0.12 0.18 rg BT /F1 9 Tf 35 742 Td 12 TL ";
        $y = 735;
        foreach (array_slice($lines, 0, 51) as $index => $line) {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $line) ?: '';
            $ascii = mb_strimwidth($ascii, 0, 92, '...');
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii);
            $isHeader = str_contains($ascii, 'SITUATION DE LA CAISSE') || str_starts_with($ascii, 'SERVICE |') || str_starts_with($ascii, 'HEURE |');
            if (str_contains($ascii, '|')) {
                $fill = $isHeader ? '0.08 0.56 0.51' : ($index % 2 === 0 ? '0.94 0.97 0.99' : '1 1 1');
                $color = $isHeader ? '1 1 1' : '0.08 0.12 0.18';
                $text .= 'ET '.$fill.' rg 30 '.($y - 3).' 535 12 re f 0.78 0.82 0.87 RG 30 '.($y - 3).' 535 12 re S '.$color.' rg BT /F1 '.($isHeader ? '7' : '6.5').' Tf 35 '.$y.' Td ('.$escaped.') Tj ';
            } else {
                $text .= '('.$escaped.") Tj T* ";
            }
            $y -= 12;
        }
        $text .= 'ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.strlen($text).' >> stream' . "\n" . $text . "\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>',
        ];
        $pdf = "%PDF-1.4\n"; $offsets = [0];
        foreach ($objects as $index => $object) { $offsets[] = strlen($pdf); $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n"; }
        $xref = strlen($pdf); $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        for ($i = 1; $i <= 5; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        return $pdf."trailer << /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    private function ensureAdmin($user, Shop $shop): void
    {
        abort_unless($user->role === 'admin' && $shop->owner_id === $user->id, 403, 'Seul l’administrateur peut supprimer les rapports.');
    }

    private function ensureAccess($user, Shop $shop): void
    {
        abort_unless(
            ($user->role === 'admin' && $shop->owner_id === $user->id)
                || $user->employee?->shop_id === $shop->id,
            403,
            'Accès non autorisé à ce point de vente.',
        );
    }
}
