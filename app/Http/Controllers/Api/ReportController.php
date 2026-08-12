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
        $headers = ['Date', 'Dépenses', 'Dettes', 'Achats crédit/virtuel', 'Remboursements', 'Moov crédit', 'Flooz', 'MoMo', 'MTN crédit', 'Celtiis', 'Encaissements', 'Décaissements', 'Total du jour', 'Validé par', 'Détails des opérations'];
        if ($format === 'pdf') {
            $lines = ['Rapports - '.$shop->name, ''];
            foreach ($closures as $closure) {
                $out = (float) collect($closure->expenses ?? [])->sum('amount')
                    + (float) collect($closure->debts ?? [])->sum('amount')
                    + (float) $closure->virtual_credit_purchase;
                $report = $shop->reports()->whereDate('date', $closure->date)->first();
                $lines[] = sprintf('%s | Encaissements: %.0f | Decaissements: %.0f | Total: %.0f FCFA', $closure->date->format('d/m/Y'), $report?->total_in ?? 0, $report?->total_out ?? 0, $report?->cash_balance ?? 0);
                $lines[] = 'Valide par: '.($closure->validator?->name ?? 'Non renseigne');
                foreach (Operation::where('shop_id', $shop->id)->whereDate('occurred_at', $closure->date)->orderBy('occurred_at')->get() as $operation) {
                    $sign = $operation->direction === 'out' ? '-' : '+';
                    $lines[] = sprintf('  %s %.0f | %s | %s%s', $sign, $operation->amount, $operation->description, $operation->phone ? $operation->phone.' | ' : '', $operation->occurred_at->format('H:i'));
                }
                $lines[] = '';
            }
            return response($this->simplePdf($lines), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="rapports-'.$shop->id.'.pdf"']);
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?><?mso-application progid="Excel.Sheet"?>';
        $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="Rapports"><Table><Row>';
        foreach ($headers as $header) $xml .= '<Cell><Data ss:Type="String">'.$escape($header).'</Data></Cell>';
        $xml .= '</Row>';
        foreach ($closures as $closure) {
            $values = $this->row($closure);
            $xml .= '<Row>';
            foreach ($values as $index => $value) {
                $type = in_array($index, [0, 13, 14], true) ? 'String' : 'Number';
                $xml .= '<Cell><Data ss:Type="'.$type.'">'.$escape($value ?? '').'</Data></Cell>';
            }
            $xml .= '</Row>';
        }
        $xml .= '</Table></Worksheet></Workbook>';
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
        return [
            $closure->date->format('d/m/Y'), $operations->where('type', 'expense')->sum('amount'),
            $operations->where('type', 'debt')->sum('amount'), $operations->where('type', 'virtual_credit_purchase')->sum('amount'),
            $operations->where('type', 'debt_repayment')->sum('amount'), $closure->moov_credit, $closure->flooz,
            $closure->momo, $closure->mtn_credit, $closure->celtiis, $report?->total_in ?? 0,
            $report?->total_out ?? 0, $report?->cash_balance ?? 0, $closure->validator?->name ?? '',
            $operations->map(fn ($operation) => sprintf('%s %.0f - %s%s', $operation->direction === 'out' ? '-' : '+', $operation->amount, $operation->description, $operation->phone ? ' ('.$operation->phone.')' : ''))->implode(' | '),
        ];
    }

    private function simplePdf(array $lines): string
    {
        $text = "0.02 0.31 0.54 rg 25 760 545 62 re f 1 1 1 rg BT /F1 18 Tf 42 796 Td (ENAGNON LEADER) Tj 0 -24 Td /F1 11 Tf (Rapport detaille des operations) Tj ET 0.08 0.12 0.18 rg BT /F1 9 Tf 35 742 Td 12 TL ";
        foreach (array_slice($lines, 0, 58) as $line) {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $line) ?: '';
            $text .= '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii).") Tj T* ";
        }
        $text .= 'ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.strlen($text).' >> stream' . "\n" . $text . "\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
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
