<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8" />
<style>
    body { font-family: 'Helvetica', sans-serif; color: #1f2937; font-size: 12px; }
    .header { background: #10222E; color: #fff; padding: 24px 32px; }
    .header h1 { margin: 0; font-size: 20px; letter-spacing: 0.5px; }
    .header p { margin: 4px 0 0; font-size: 11px; color: #8B7FE0; }
    .issuer { padding: 14px 32px 0; font-size: 11px; color: #6b7280; }
    .issuer strong { color: #111827; }
    .content { padding: 24px 32px; }
    .row { display: table; width: 100%; margin-bottom: 20px; }
    .col { display: table-cell; width: 50%; vertical-align: top; }
    .col.right { text-align: right; }
    .label { color: #9ca3af; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
    .value { font-size: 13px; font-weight: bold; color: #111827; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 16px; }
    table.items th { text-align: left; font-size: 10px; text-transform: uppercase; color: #9ca3af; border-bottom: 2px solid #e5e7eb; padding: 8px 4px; }
    table.items td { padding: 10px 4px; border-bottom: 1px solid #f3f4f6; font-size: 12px; }
    .totals { width: 260px; margin-left: auto; margin-top: 16px; }
    .totals td { padding: 4px 0; font-size: 12px; }
    .totals .grand td { border-top: 2px solid #10222E; font-weight: bold; font-size: 14px; padding-top: 8px; }
    .status { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: bold; }
    .status.paid { background: #dcfce7; color: #166534; }
    .status.overdue { background: #fee2e2; color: #991b1b; }
    .status.void { background: #f3f4f6; color: #6b7280; }
    .status.draft, .status.sent { background: #fef3c7; color: #92400e; }
    .footer { padding: 16px 32px; font-size: 10px; color: #9ca3af; border-top: 1px solid #e5e7eb; margin-top: 24px; }
</style>
</head>
<body>
    <div class="header">
        <h1>QAYED</h1>
        <p>Enregistrement numérique des hôtes</p>
    </div>

    <div class="issuer">
        <strong>{{ $issuer->company_name ?? 'Qayed' }}</strong>
        @if($issuer->company_mf)
            &nbsp;·&nbsp;MF : {{ $issuer->company_mf }}
        @endif
        @if($issuer->company_rc)
            &nbsp;·&nbsp;RC : {{ $issuer->company_rc }}
        @endif
        @if($issuer->company_address)
            <br>{{ $issuer->company_address }}
        @endif
    </div>

    <div class="content">
        <div class="row">
            <div class="col">
                <p class="label">Facturé à</p>
                <p class="value">{{ $org?->name ?? '—' }}</p>
                @if($org?->entity_type)
                    <p>{{ $org->entity_type === 'individual' ? 'Particulier' : 'Société' }}</p>
                @endif
                @if($org?->registration_number)
                    <p>RC / Matricule : {{ $org->registration_number }}</p>
                @endif
                @if($org?->address && is_array($org->address) && count(array_filter($org->address)))
                    <p>{{ implode(', ', array_filter($org->address)) }}</p>
                @endif
                @if($org?->contact_email)
                    <p>{{ $org->contact_email }}</p>
                @endif
            </div>
            <div class="col right">
                <p class="label">Facture N°</p>
                <p class="value">{{ $invoice->invoice_number }}</p>
                <p class="label" style="margin-top:12px;">Émise le</p>
                <p>{{ $invoice->created_at->format('d/m/Y') }}</p>
                @if($invoice->due_at)
                    <p class="label" style="margin-top:8px;">Échéance</p>
                    <p>{{ $invoice->due_at->format('d/m/Y') }}</p>
                @endif
                <p style="margin-top:12px;"><span class="status {{ $invoice->status }}">{{ strtoupper($invoice->status) }}</span></p>
            </div>
        </div>

        <table class="items">
            <thead>
                <tr><th>Description</th><th style="text-align:right;">Montant</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        Abonnement Qayed — pack {{ $plan?->name ?? '—' }}
                        @if($invoice->subscription)
                            <br><span style="color:#9ca3af;">Cycle {{ $invoice->subscription->billing_cycle === 'yearly' ? 'annuel' : 'mensuel' }}</span>
                        @endif
                    </td>
                    <td style="text-align:right;">{{ \App\Support\Money::tnd($invoice->amount, $invoice->currency) }}</td>
                </tr>
            </tbody>
        </table>

        <table class="totals">
            <tr><td>Sous-total</td><td style="text-align:right;">{{ \App\Support\Money::tnd($invoice->amount, $invoice->currency) }}</td></tr>
            <tr><td>TVA</td><td style="text-align:right;">{{ \App\Support\Money::tnd($invoice->tax_amount, $invoice->currency) }}</td></tr>
            <tr class="grand"><td>Total</td><td style="text-align:right;">{{ \App\Support\Money::tnd($invoice->total_amount, $invoice->currency) }}</td></tr>
        </table>

        @if($invoice->status === 'paid' && $invoice->paid_at)
            <p style="margin-top:16px; color:#166534;">Payée le {{ $invoice->paid_at->format('d/m/Y') }}@if($invoice->payment_method) via {{ $invoice->payment_method }}@endif</p>
        @endif

        @if($invoice->notes)
            <p style="margin-top:16px; color:#6b7280;">{{ $invoice->notes }}</p>
        @endif
    </div>

    <div class="footer">
        © {{ date('Y') }} Qayed — Plateforme agréée MdI
    </div>
</body>
</html>
