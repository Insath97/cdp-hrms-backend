<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payslip - {{ $payroll->month }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            padding: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
        }
        .company-name {
            font-size: 24px;
            font-weight: bold;
        }
        .payslip-title {
            font-size: 18px;
            margin-top: 10px;
        }
        .employee-info {
            margin-bottom: 20px;
            padding: 10px;
            background: #f5f5f5;
        }
        .section {
            margin-bottom: 20px;
        }
        .section-title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 10px;
            border-left: 4px solid #333;
            padding-left: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        td, th {
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .total-row {
            font-weight: bold;
            background: #f9f9f9;
        }
        .signature {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
        }
        .signature-img {
            max-width: 200px;
            margin-top: 10px;
        }
        .footer {
            margin-top: 50px;
            text-align: center;
            font-size: 12px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="company-name">Empire HRMS</div>
        <div class="payslip-title">Monthly Payslip - {{ $payroll->month }}</div>
    </div>

    <div class="employee-info">
        <strong>Employee Information</strong><br>
        Name: {{ $user->name }}<br>
        Employee ID: {{ $user->employee_id ?? 'N/A' }}<br>
        Department: {{ $user->department ?? 'N/A' }}
    </div>

    <div class="section">
        <div class="section-title">Earnings</div>
        <table>
            <tr>
                <td>Basic Salary</td>
                <td align="right">LKR {{ number_format($payroll->basic, 2) }}</td>
            </tr>
            <tr>
                <td>Fixed Allowances</td>
                <td align="right">LKR {{ number_format($payroll->allowances, 2) }}</td>
            </tr>
            <tr class="total-row">
                <td>Gross Salary</td>
                <td align="right">LKR {{ number_format($payroll->basic + $payroll->allowances, 2) }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Deductions</div>
        <table>
            <tr>
                <td>EPF (Employee 8%)</td>
                <td align="right">LKR {{ number_format($payroll->epf_employee, 2) }}</td>
            </tr>
            @if($payroll->deductions > 0)
            <tr>
                <td>Loss of Pay / Other Deductions</td>
                <td align="right">LKR {{ number_format($payroll->deductions, 2) }}</td>
            </tr>
            @endif
            <tr class="total-row">
                <td>Total Deductions</td>
                <td align="right">LKR {{ number_format($payroll->epf_employee + $payroll->deductions, 2) }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <table>
            <tr class="total-row">
                <td><strong>Net Pay</strong></td>
                <td align="right"><strong>LKR {{ number_format($payroll->net, 2) }}</strong></td>
            </tr>
        </table>
    </div>

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