<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payslip - {{ $period_label }}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; padding: 20px; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #333; padding-bottom: 10px; }
        .company-name { font-size: 22px; font-weight: bold; }
        .payslip-title { font-size: 16px; margin-top: 6px; color: #555; }
        .section { margin-bottom: 16px; }
        .section-title { font-size: 14px; font-weight: bold; margin-bottom: 8px; border-left: 4px solid #333; padding-left: 8px; background: #f5f5f5; padding: 6px 8px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        td, th { padding: 5px 8px; text-align: left; border-bottom: 1px solid #ddd; font-size: 12px; }
        .total-row { font-weight: bold; background: #f9f9f9; }
        .label { color: #666; }
        .value { font-weight: 600; text-align: right; }
        .signature { margin-top: 30px; padding-top: 16px; border-top: 1px solid #ddd; }
        .signature-img { max-width: 180px; margin-top: 8px; }
        .footer { margin-top: 30px; text-align: center; font-size: 10px; color: #999; }
        .two-col { display: flex; gap: 16px; }
        .col { flex: 1; }
        .employee-info { background: #f5f5f5; padding: 10px; border-radius: 4px; margin-bottom: 16px; font-size: 12px; }
        .employee-info strong { font-size: 13px; }
        .badge { display: inline-block; background: #e0e0e0; padding: 2px 8px; border-radius: 3px; font-size: 11px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="company-name">Empire HRMS</div>
        <div class="payslip-title">Monthly Payslip — {{ $period_label }}</div>
    </div>

    <div class="employee-info">
        <strong>{{ $user->name }}</strong><br>
        Employee ID: <span class="badge">{{ $user->employee_id ?? 'N/A' }}</span><br>
        Period: {{ $period_label }}
    </div>

    @if(isset($metrics))
    <div class="section">
        <div class="section-title">Package Breakdown</div>
        <table>
            @if($metrics['monthly_target'] > 0)
            <tr><td class="label">Monthly Target</td><td class="value">LKR {{ number_format($metrics['monthly_target'], 2) }}</td></tr>
            @endif
            @if($metrics['basic_salary'] > 0)
            <tr><td class="label">Basic Salary</td><td class="value">LKR {{ number_format($metrics['basic_salary'], 2) }}</td></tr>
            @endif
            @if($metrics['travel_reimbursement'] > 0)
            <tr><td class="label">Travel Reimbursement</td><td class="value">LKR {{ number_format($metrics['travel_reimbursement'], 2) }}</td></tr>
            @endif
            @if($metrics['vehicle_allowance'] > 0)
            <tr><td class="label">Vehicle Allowance</td><td class="value">LKR {{ number_format($metrics['vehicle_allowance'], 2) }}</td></tr>
            @endif
            @if($metrics['performance_allowance'] > 0)
            <tr><td class="label">Performance Allowance</td><td class="value">LKR {{ number_format($metrics['performance_allowance'], 2) }}</td></tr>
            @endif
            @if($metrics['incentive'] > 0)
            <tr><td class="label">Incentive</td><td class="value">LKR {{ number_format($metrics['incentive'], 2) }}</td></tr>
            @endif
            @if($metrics['position_allowance'] > 0)
            <tr><td class="label">Position Allowance</td><td class="value">LKR {{ number_format($metrics['position_allowance'], 2) }}</td></tr>
            @endif
            <tr class="total-row"><td>Total Package</td><td class="value">LKR {{ number_format($metrics['total_package'], 2) }}</td></tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Payment Calculation</div>
        <table>
            <tr><td class="label">Achievement</td><td class="value">{{ number_format($metrics['achievement_percentage'], 2) }}%</td></tr>
            <tr><td class="label">Payment Rate</td><td class="value">{{ $metrics['payment_percentage'] }}%</td></tr>
            <tr><td class="label">Payment Criteria</td><td class="value">{{ $metrics['payment_criteria'] }}</td></tr>
            <tr class="total-row"><td>Calculated Payment</td><td class="value">LKR {{ number_format($metrics['calculated_payment'], 2) }}</td></tr>
        </table>
    </div>
    @endif

    @if(isset($payroll) && $payroll->basic > 0)
    <div class="section">
        <div class="section-title">Earnings</div>
        <table>
            <tr><td class="label">Basic Salary</td><td class="value">LKR {{ number_format($payroll->basic, 2) }}</td></tr>
            <tr><td class="label">Fixed Allowances</td><td class="value">LKR {{ number_format($payroll->allowances, 2) }}</td></tr>
            <tr class="total-row"><td>Gross Salary</td><td class="value">LKR {{ number_format($payroll->basic + $payroll->allowances, 2) }}</td></tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Deductions</div>
        <table>
            <tr><td class="label">EPF (Employee 8%)</td><td class="value">LKR {{ number_format($payroll->epf_employee, 2) }}</td></tr>
            @if($payroll->deductions > 0)
            <tr><td class="label">Loss of Pay / Other Deductions</td><td class="value">LKR {{ number_format($payroll->deductions, 2) }}</td></tr>
            @endif
            <tr class="total-row"><td>Total Deductions</td><td class="value">LKR {{ number_format($payroll->epf_employee + $payroll->deductions, 2) }}</td></tr>
        </table>
    </div>

    <div class="section">
        <table><tr class="total-row"><td><strong>Net Pay</strong></td><td class="value"><strong>LKR {{ number_format($payroll->net, 2) }}</strong></td></tr></table>
    </div>
    @endif

    <div class="signature">
        <strong>Authorized Signature</strong><br>
        @if(isset($signature))
            <img src="{{ $signature }}" class="signature-img" alt="E-Signature">
        @endif
        <br>
        <small>Digitally signed by {{ $approved_by->name }} on {{ $approved_date->format('F d, Y H:i:s') }}</small>
    </div>

    <div class="footer">
        This is a computer-generated document and requires no physical signature.<br>
        For any discrepancies, please contact HR within 7 days.
    </div>
</body>
</html>
