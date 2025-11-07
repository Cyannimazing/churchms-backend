<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <title>Payment Receipt</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            margin: 0;
            padding: 30px;
            background-color: #ffffff;
            font-size: 14px;
            line-height: 1.5;
        }
        .receipt-card {
            max-width: 320px;
            margin: 0 auto;
            background-color: #ffffff;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 2rem;
        }
        /* Header */
        .receipt-header {
            text-align: center;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 1rem;
        }
        .brand-name {
            font-size: 20px;
            font-weight: bold;
            color: #111827;
            margin: 0 0 0.5rem 0;
        }
        .receipt-title {
            font-size: 18px;
            font-weight: 600;
            color: #374151;
            margin: 0 0 0.5rem 0;
        }
        .generated-date {
            font-size: 12px;
            color: #6b7280;
            margin: 0;
        }
        /* Content */
        .receipt-details {
            margin-bottom: 1.5rem;
        }
        .details-table {
            width: 100%;
            border-collapse: collapse;
        }
        .details-table td {
            padding: 0.25rem 0;
            vertical-align: top;
            border: none;
        }
        .detail-label {
            font-size: 14px;
            color: #6b7280;
            text-align: left;
            width: 40%;
            padding-right: 1rem;
            white-space: nowrap;
        }
        .detail-value {
            font-size: 14px;
            font-weight: 500;
            color: #111827;
            text-align: right;
            width: 60%;
            white-space: nowrap;
        }
        /* Amount section */
        .amount-section {
            border-top: 1px solid #e5e7eb;
            margin-top: 1rem;
            padding-top: 0.75rem;
        }
        .amount-table {
            width: 100%;
            border-collapse: collapse;
        }
        .amount-table td {
            padding: 0;
            border: none;
        }
        .amount-label {
            font-size: 16px;
            font-weight: 600;
            color: #111827;
            text-align: left;
        }
        .amount-value {
            font-size: 16px;
            font-weight: bold;
            color: #16a34a;
            text-align: right;
        }
        /* Footer */
        .receipt-footer {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid #e5e7eb;
        }
        .thank-you-message {
            font-size: 14px;
            color: #4b5563;
            margin: 0 0 0.25rem 0;
        }
        .keep-receipt-note {
            font-size: 12px;
            color: #6b7280;
            margin: 0 0 0.5rem 0;
        }
        .important-warning {
            font-size: 12px;
            font-weight: 500;
            color: #dc2626;
            margin: 0;
        }
    </style>
</head>
<body>
    <div class="receipt-card">
        <!-- Receipt Header -->
        <div class="receipt-header">
            <h2 class="receipt-title">Payment Receipt</h2>
            <p class="generated-date">Generated on {{ date('F j, Y, g:i A') }}</p>
        </div>
        
        <!-- Receipt Details -->
        <div class="receipt-details">
            <table class="details-table">
                <tr>
                    <td class="detail-label">Transaction ID:</td>
                    <td class="detail-value">{{ $receiptNumber }}</td>
                </tr>
                <tr>
                    <td class="detail-label">Receipt Code:</td>
                    <td class="detail-value">{{ $transaction->receipt_code ?? 'N/A' }}</td>
                </tr>
                <tr>
                    <td class="detail-label">Date:</td>
                    <td class="detail-value">{{ $formattedDate }}</td>
                </tr>
                <tr>
                    <td class="detail-label">Church:</td>
                    <td class="detail-value">{{ $church->ChurchName ?? 'holy trinity' }}</td>
                </tr>
                <tr>
                    <td class="detail-label">Service:</td>
                    <td class="detail-value">{{ $service ?? 'Baptism' }}</td>
                </tr>
                <tr>
                    <td class="detail-label">Payment Method:</td>
                    <td class="detail-value">{{ $paymentMethod }}</td>
                </tr>
            </table>
            
            <!-- Amount Section -->
            <div class="amount-section">
                <table class="amount-table">
                    <tr>
                        <td class="amount-label">Amount Paid:</td>
                        <td class="amount-value">P{{ number_format($amount, 2) }}</td>
                    </tr>
                </table>
            </div>
        </div>
        
        <!-- Receipt Footer -->
        <div class="receipt-footer">
            <p class="thank-you-message">Thank you for your appointment!</p>
            <p class="keep-receipt-note">Keep this receipt for your records.</p>
            @if($transaction->receipt_code)
            <p class="important-warning">
                Important: Save your Receipt Code for refunds if needed.
            </p>
            @endif
        </div>
    </div>
</body>
</html>