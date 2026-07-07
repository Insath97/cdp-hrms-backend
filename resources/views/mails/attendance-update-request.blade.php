<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Update Request - {{ config('app.name') }}</title>
    <style>
        :root {
            --primary: #298c77;
            --primary-light: #e1f8f3;
            --primary-dark: #1e594f;
            --text-main: #334155;
            --text-muted: #64748b;
            --bg-page: #f8fafc;
            --bg-card: #ffffff;
            --border: #e2e8f0;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background-color: var(--bg-page);
            margin: 0;
            padding: 20px;
            color: var(--text-main);
            line-height: 1.6;
        }

        .email-wrapper {
            max-width: 600px;
            margin: 0 auto;
            background: var(--bg-card);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border);
        }

        .header {
            background-color: var(--primary);
            padding: 35px 30px;
            text-align: center;
            color: #ffffff;
        }

        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 800;
            letter-spacing: -0.025em;
            color: #ffffff;
        }

        .content {
            padding: 30px;
        }

        .salutation {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 12px;
        }

        .intro-text {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 25px;
        }

        .status-banner {
            background-color: var(--primary-light);
            color: var(--primary-dark);
            padding: 10px;
            border-radius: 8px;
            text-align: center;
            font-weight: 700;
            font-size: 13px;
            margin-bottom: 25px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .section {
            margin-bottom: 25px;
        }

        .section-title {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            color: var(--primary);
            letter-spacing: 0.1em;
            margin-bottom: 12px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 6px;
        }

        .info-table {
            width: 100%;
            border-collapse: collapse;
        }

        .info-row td {
            padding: 10px 0;
            font-size: 14px;
            border-bottom: 1px solid #f1f5f9;
        }

        .label {
            color: var(--text-muted);
            font-weight: 500;
            width: 40%;
        }

        .value {
            color: var(--text-main);
            font-weight: 600;
            text-align: right;
        }

        .notes-box {
            background: #f1f5f9;
            padding: 15px;
            border-radius: 8px;
            font-size: 13px;
            color: var(--text-main);
            border-left: 4px solid var(--primary);
        }

        .btn-container {
            text-align: center;
            margin: 30px 0 10px 0;
        }

        .btn {
            display: inline-block;
            background-color: var(--primary);
            color: #ffffff !important;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            box-shadow: 0 4px 6px -1px rgba(41, 140, 119, 0.2);
            transition: background-color 0.2s;
        }

        .btn:hover {
            background-color: var(--primary-dark);
        }

        .footer {
            background: #f8fafc;
            padding: 25px;
            text-align: center;
            font-size: 12px;
            color: var(--text-muted);
            border-top: 1px solid var(--border);
        }
    </style>
</head>

<body>
    <div class="email-wrapper">
        <div class="header">
            <h1>Attendance Update Request</h1>
        </div>

        <div class="content">
            <div class="salutation">Hello {{ $data['manager_name'] }},</div>
            <div class="intro-text">
                <strong>{{ $data['employee_name'] }}</strong> has submitted a request to update their attendance record.
            </div>

            <div class="status-banner">
                Status: Pending Manager Approval
            </div>

            <div class="section">
                <div class="section-title">Request Details</div>
                <table class="info-table">
                    <tr class="info-row">
                        <td class="label">Employee</td>
                        <td class="value">{{ $data['employee_name'] }}</td>
                    </tr>
                    <tr class="info-row">
                        <td class="label">Date</td>
                        <td class="value">{{ \Carbon\Carbon::parse($data['date'])->format('F d, Y') }}</td>
                    </tr>
                    <tr class="info-row">
                        <td class="label">Requested Clock In</td>
                        <td class="value">
                            {{ $data['requested_clock_in'] ? \Carbon\Carbon::parse($data['requested_clock_in'])->format('h:i A') : 'No Change' }}
                        </td>
                    </tr>
                    <tr class="info-row">
                        <td class="label">Requested Clock Out</td>
                        <td class="value">
                            {{ $data['requested_clock_out'] ? \Carbon\Carbon::parse($data['requested_clock_out'])->format('h:i A') : 'No Change' }}
                        </td>
                    </tr>
                </table>
            </div>

            @if(!empty($data['reason']))
                <div class="section">
                    <div class="section-title">Reason for Request</div>
                    <div class="notes-box">
                        {{ $data['reason'] }}
                    </div>
                </div>
            @endif

            <div class="btn-container">
                <a href="{{ $data['action_url'] }}" class="btn" target="_blank">Review Request</a>
            </div>
        </div>

        <div class="footer">
            <p>This is an automated notification from the {{ config('app.name') }} HR Portal.</p>
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </div>
    </div>
</body>

</html>
