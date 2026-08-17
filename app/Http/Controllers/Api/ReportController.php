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
                $other('virtual_credit_purchase', 'Achats crédit / virtuel', 'in'),
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
                'virtual_balance_after' => $operation->virtual_balance_after,
                'phone' => $operation->phone, 'network' => $operation->network,
                'description' => $operation->description,
                'time' => $operation->occurred_at?->format('H:i'),
            ])->values(),
            'expenses' => $closure->expenses ?? [],
            'debts' => $closure->debts ?? [],
            'opening_balance' => round((float) $shop->operations()->where('occurred_at', '<', $report->date->copy()->startOfDay())->selectRaw("SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) as balance")->value('balance'), 2),
            'cash_balance_after' => $this->openingBalance($shop->id, $report->date) + (float) $report->cash_balance,
            'virtual_balances' => $this->virtualBalancesAt($shop, $report->date),
        ]);
    }

    public function export(Request $request, Shop $shop)
    {
        $this->ensureAdmin($this->authOrFail($request), $shop);
        $data = $request->validate(['format' => 'nullable|in:xls,pdf', 'report_id' => 'nullable|integer', 'report_type' => 'nullable|in:detailed,global']);
        $format = $data['format'] ?? 'xls';
        $reportType = $data['report_type'] ?? 'detailed';
        $closures = $shop->dailyClosures()->where('status', 'validated')
            ->when(isset($data['report_id']), function ($query) use ($data, $shop) {
                $report = $shop->reports()->findOrFail($data['report_id']);
                $query->whereDate('date', $report->date);
            })->with('validator:id,name')->orderByDesc('date')->get();
        abort_if($closures->isEmpty(), 422, 'Aucun rapport validé à exporter.');
        $escape = fn ($value) => htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $headers = ['Date', 'Caisse ouverture', 'Dépenses', 'Dettes', 'Achats crédit/virtuel', 'Remboursements', 'Moov crédit', 'Flooz', 'MoMo', 'MTN crédit', 'Celtiis', 'Encaissements', 'Décaissements', 'Total du jour', 'Caisse après journée', 'Validé par', 'Détails des opérations'];
        if ($format === 'pdf') {
            $reports = [];
            foreach ($closures as $closure) {
                $report = Report::where('shop_id', $shop->id)->whereDate('date', $closure->date)->first();
                $opening = $this->openingBalance($shop->id, $closure->date);
                $virtual = $this->virtualBalancesAt($shop, $closure->date);
                $lines = ['Point de vente : '.$shop->name, 'Date : '.$closure->date->format('d/m/Y').'    Agent : '.($closure->validator?->name ?? 'Non renseigné')];
                if ($reportType === 'global') {
                    $cashAfter = $opening + ($report?->cash_balance ?? 0);
                    $lines[] = $this->fixedRow(['RUBRIQUE', 'MONTANT (FCFA)'], [56, 24]);
                    $lines[] = $this->fixedRow(['Espèces', $this->money($cashAfter)], [56, 24]);
                    foreach ($virtual as $item) $lines[] = $this->fixedRow([$item['label'], $this->money($item['balance'])], [56, 24]);
                    $lines[] = $this->fixedRow(['TOTAL DES SOLDES', $this->money($cashAfter + collect($virtual)->sum('balance'))], [56, 24]);
                    $operations = Operation::where('shop_id', $shop->id)->whereDate('occurred_at', $closure->date)->get();
                    $lines[] = $this->fixedRow(['Total encaissements espèces', $this->money($report?->total_in ?? 0)], [56, 24]);
                    $lines[] = $this->fixedRow(['Total décaissements espèces', '-'.$this->money($report?->total_out ?? 0)], [56, 24]);
                    $lines[] = $this->fixedRow(['Dépenses', '-'.$this->money($operations->where('type', 'expense')->sum('amount'))], [56, 24]);
                    $lines[] = $this->fixedRow(['Achats crédit / virtuel', '+'.$this->money($operations->where('type', 'virtual_credit_purchase')->sum('amount'))], [56, 24]);
                    $lines[] = $this->fixedRow(['Dettes', '-'.$this->money($operations->where('type', 'debt')->sum('amount'))], [56, 24]);
                    $lines[] = $this->fixedRow(['TOTAL JOURNÉE', $this->money($report?->cash_balance ?? 0)], [56, 24]);
                } else {
                    $lines[] = $this->fixedRow(['SITUATION DE LA CAISSE', 'MONTANT'], [56, 24]);
                    foreach ([['Caisse à l’ouverture', $opening], ['Total encaissements', $report?->total_in ?? 0], ['Total décaissements', $report?->total_out ?? 0], ['Total du jour', $report?->cash_balance ?? 0], ['Caisse après la journée', $opening + ($report?->cash_balance ?? 0)]] as $row) $lines[] = $this->fixedRow([$row[0], $this->money($row[1])], [56, 24]);
                    $lines[] = $this->fixedRow(['SOLDE VIRTUEL PAR RÉSEAU', 'MONTANT'], [56, 24]);
                    foreach ($virtual as $item) $lines[] = $this->fixedRow([$item['label'], $this->money($item['balance'])], [56, 24]);
                    $lines[] = $this->fixedRow(['SERVICE', 'ENCAISSEMENTS', 'DÉCAISSEMENTS', 'SOLDE'], [24, 18, 18, 18]);
                    foreach ($this->serviceRows($shop->id, $closure->date) as $row) $lines[] = $this->fixedRow([$row['label'], $this->money($row['entries']), $this->money($row['outputs']), $this->money($row['entries'] - $row['outputs'])], [24, 18, 18, 18]);
                    $lines[] = $this->fixedRow(['HEURE', 'SERVICE', 'SENS', 'MOTIF', 'NUMÉRO', 'MONTANT'], [7, 14, 16, 22, 14, 14]);
                    foreach (Operation::where('shop_id', $shop->id)->whereDate('occurred_at', $closure->date)->orderBy('occurred_at')->get() as $operation) $lines[] = $this->fixedRow([$operation->occurred_at->format('H:i'), $this->serviceLabel($operation), $operation->direction === 'in' ? 'Encaissement' : 'Décaissement', $operation->description ?: 'Sans motif', $operation->phone ?: '-', ($operation->direction === 'out' ? '-' : '+').$this->money($operation->amount)], [7, 14, 16, 22, 14, 14]);
                }
                $reports[] = $lines;
            }
            $pdf = $this->simplePdf($reports, $reportType);
            return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Length' => strlen($pdf), 'Content-Disposition' => 'attachment; filename="rapports-'.$shop->id.'.pdf"']);
        }
        $xml = $this->excelXml($shop, $closures, $reportType);
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

    private function virtualBalancesAt(Shop $shop, $date): array
    {
        $labels = ['moov_credit' => 'Moov crédit', 'flooz' => 'Flooz', 'momo' => 'MoMo', 'mtn_credit' => 'MTN crédit', 'celtiis' => 'Celtiis'];
        $balances = collect($labels)->mapWithKeys(fn ($label, $service) => [$service => (float) $shop->{$service.'_initial_balance'}])->all();
        foreach ($shop->operations()->where('occurred_at', '<=', $date->copy()->endOfDay())->orderBy('occurred_at')->orderBy('id')->get() as $operation) {
            $service = $operation->type === 'virtual_credit_purchase' ? $operation->network : (isset($labels[$operation->service]) ? $operation->service : null);
            if (! $service || ! isset($balances[$service])) continue;
            $balances[$service] += $operation->type === 'virtual_credit_purchase' ? (float) $operation->amount : ($operation->direction === 'in' ? -(float) $operation->amount : (float) $operation->amount);
        }
        return collect($labels)->map(fn ($label, $service) => ['service' => $service, 'label' => $label, 'balance' => round($balances[$service], 2)])->values()->all();
    }

    private function money($value): string { return number_format((float) $value, 0, ',', ' ').' FCFA'; }

    private function fixedRow(array $cells, array $widths): string
    {
        return collect($cells)->map(function ($cell, $index) use ($widths) {
            $width = $widths[$index]; $value = mb_strimwidth((string) $cell, 0, $width - 1, '…', 'UTF-8');
            return $value.str_repeat(' ', max(0, $width - mb_strwidth($value, 'UTF-8')));
        })->implode('');
    }

    private function serviceRows(int $shopId, $date): array
    {
        $operations = Operation::where('shop_id', $shopId)->whereDate('occurred_at', $date)->get();
        $definitions = ['expense' => 'Dépenses', 'debt' => 'Dettes', 'debt_repayment' => 'Remboursements', 'virtual_credit_purchase' => 'Achat crédit/virtuel', 'moov_credit' => 'Moov crédit', 'flooz' => 'Flooz', 'momo' => 'MoMo', 'mtn_credit' => 'MTN crédit', 'celtiis' => 'Celtiis'];
        return collect($definitions)->map(function ($label, $key) use ($operations) {
            $items = in_array($key, ['expense', 'debt', 'debt_repayment', 'virtual_credit_purchase'], true) ? $operations->where('type', $key) : $operations->where('service', $key);
            $entries = $key === 'virtual_credit_purchase' ? (float) $items->sum('amount') : (float) $items->where('direction', 'in')->sum('amount');
            $outputs = $key === 'virtual_credit_purchase' ? 0 : (float) $items->where('direction', 'out')->sum('amount');
            return ['label' => $label, 'entries' => $entries, 'outputs' => $outputs];
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

    private function excelXml(Shop $shop, $closures, string $reportType = 'detailed'): string
    {
        $escape = fn ($value) => htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xml = '<?xml version="1.0" encoding="UTF-8"?><?mso-application progid="Excel.Sheet"?><Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Styles><Style ss:ID="Header"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#159586" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center"/></Style><Style ss:ID="Title"><Font ss:Bold="1" ss:Size="16" ss:Color="#064F82"/></Style><Style ss:ID="Money"><NumberFormat ss:Format="# ##0 \&quot;FCFA\&quot;"/></Style></Styles>';
        foreach ($closures as $closure) {
            $report = Report::where('shop_id', $shop->id)->whereDate('date', $closure->date)->first();
            $opening = $this->openingBalance($shop->id, $closure->date);
            $virtual = $this->virtualBalancesAt($shop, $closure->date);
            $name = 'Synthese-'.$closure->date->format('dmY');
            $xml .= '<Worksheet ss:Name="'.$name.'"><Table><Column ss:Width="180"/><Column ss:Width="120"/><Row><Cell ss:StyleID="Title"><Data ss:Type="String">ENAGNON LEADER</Data></Cell></Row>';
            $summaryRows = [['Type de rapport', $reportType === 'global' ? 'Rapport global' : 'Rapport détaillé'], ['Point de vente', $shop->name], ['Date', $closure->date->format('d/m/Y')], ['Validé par', $closure->validator?->name ?? 'Non renseigné'], ['Espèces après journée', $opening + ($report?->cash_balance ?? 0)], ...collect($virtual)->map(fn ($item) => [$item['label'].' - solde virtuel', $item['balance']])->all(), ['Total encaissements', $report?->total_in ?? 0], ['Total décaissements', $report?->total_out ?? 0], ['Total du jour', $report?->cash_balance ?? 0]];
            foreach ($summaryRows as $row) {
                $xml .= '<Row><Cell><Data ss:Type="String">'.$escape($row[0]).'</Data></Cell><Cell'.(is_numeric($row[1]) ? ' ss:StyleID="Money"' : '').'><Data ss:Type="'.(is_numeric($row[1]) ? 'Number' : 'String').'">'.$escape($row[1]).'</Data></Cell></Row>';
            }
            if ($reportType === 'global') { $xml .= '</Table></Worksheet>'; continue; }
            $xml .= '</Table></Worksheet><Worksheet ss:Name="Services-'.$closure->date->format('dmY').'"><Table><Column ss:Width="170"/><Column ss:Width="110"/><Column ss:Width="110"/><Column ss:Width="110"/><Row>';
            foreach (['Service','Encaissements','Décaissements','Solde'] as $header) $xml .= '<Cell ss:StyleID="Header"><Data ss:Type="String">'.$escape($header).'</Data></Cell>';
            $xml .= '</Row>';
            foreach ($this->serviceRows($shop->id, $closure->date) as $row) $xml .= '<Row><Cell><Data ss:Type="String">'.$escape($row['label']).'</Data></Cell><Cell ss:StyleID="Money"><Data ss:Type="Number">'.$row['entries'].'</Data></Cell><Cell ss:StyleID="Money"><Data ss:Type="Number">'.$row['outputs'].'</Data></Cell><Cell ss:StyleID="Money"><Data ss:Type="Number">'.($row['entries']-$row['outputs']).'</Data></Cell></Row>';
            $xml .= '</Table></Worksheet><Worksheet ss:Name="Operations-'.$closure->date->format('dmY').'"><Table><Column ss:Width="60"/><Column ss:Width="110"/><Column ss:Width="105"/><Column ss:Width="210"/><Column ss:Width="100"/><Column ss:Width="110"/><Row>';
            foreach (['Heure','Service','Sens','Motif','Numéro','Montant FCFA'] as $header) $xml .= '<Cell ss:StyleID="Header"><Data ss:Type="String">'.$escape($header).'</Data></Cell>';
            $xml .= '</Row>';
            foreach (Operation::where('shop_id',$shop->id)->whereDate('occurred_at',$closure->date)->orderBy('occurred_at')->get() as $operation) $xml .= '<Row><Cell><Data ss:Type="String">'.$operation->occurred_at->format('H:i').'</Data></Cell><Cell><Data ss:Type="String">'.$escape($this->serviceLabel($operation)).'</Data></Cell><Cell><Data ss:Type="String">'.($operation->direction==='in'?'Encaissement':'Décaissement').'</Data></Cell><Cell><Data ss:Type="String">'.$escape($operation->description).'</Data></Cell><Cell><Data ss:Type="String">'.$escape($operation->phone).'</Data></Cell><Cell ss:StyleID="Money"><Data ss:Type="Number">'.($operation->direction==='out'?-$operation->amount:$operation->amount).'</Data></Cell></Row>';
            $xml .= '</Table></Worksheet>';
        }
        return $xml.'</Workbook>';
    }

    private function simplePdf(array $reports, string $reportType = 'detailed'): string
    {
        $streams = [];
        foreach ($reports as $lines) {
            foreach (array_chunk($lines, 48) as $chunkIndex => $chunk) {
                $subtitle = ($reportType === 'global' ? 'Rapport global' : 'Rapport détaillé').($chunkIndex ? ' - suite' : '');
                $subtitle = iconv('UTF-8', 'WINDOWS-1252//TRANSLIT//IGNORE', $subtitle) ?: $subtitle;
                $subtitle = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $subtitle);
                $text = "0.02 0.31 0.54 rg 25 760 545 62 re f 1 1 1 rg BT /F2 18 Tf 42 796 Td (ENAGNON LEADER) Tj 0 -24 Td /F1 11 Tf (".$subtitle.") Tj ET ";
                $y = 735;
                foreach ($chunk as $index => $line) {
                    $line = mb_strimwidth($line, 0, 92, '...', 'UTF-8');
                    $ascii = iconv('UTF-8', 'WINDOWS-1252//TRANSLIT//IGNORE', $line) ?: '';
                    $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii);
                    $isHeader = str_contains($line, 'SITUATION DE LA CAISSE') || str_contains($line, 'SOLDE VIRTUEL') || str_starts_with($line, 'SERVICE') || str_starts_with($line, 'HEURE') || str_starts_with($line, 'RUBRIQUE');
                    $fill = $isHeader ? '0.08 0.56 0.51' : ($index % 2 === 0 ? '0.94 0.97 0.99' : '1 1 1');
                    $color = $isHeader ? '1 1 1' : '0.08 0.12 0.18';
                    $text .= $fill.' rg 30 '.($y - 3).' 535 12 re f 0.78 0.82 0.87 RG 30 '.($y - 3).' 535 12 re S '.$color.' rg BT /F1 '.($isHeader ? '7' : '6.5').' Tf 35 '.$y.' Td ('.$escaped.') Tj ET ';
                    $y -= 14;
                }
                $streams[] = $text;
            }
        }
        $pageCount = count($streams); $font1 = 3 + ($pageCount * 2); $font2 = $font1 + 1;
        $kids = []; for ($i = 0; $i < $pageCount; $i++) $kids[] = (3 + $i * 2).' 0 R';
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$pageCount.' >>'];
        foreach ($streams as $index => $stream) {
            $contentId = 4 + $index * 2;
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 '.$font1.' 0 R /F2 '.$font2.' 0 R >> >> /Contents '.$contentId.' 0 R >>';
            $objects[] = '<< /Length '.strlen($stream).' >> stream' . "\n" . $stream . "\nendstream";
        }
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $pdf = "%PDF-1.4\n"; $offsets = [0];
        foreach ($objects as $index => $object) { $offsets[] = strlen($pdf); $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n"; }
        $size = count($objects) + 1; $xref = strlen($pdf); $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
        for ($i = 1; $i < $size; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        return $pdf."trailer << /Size {$size} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
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
