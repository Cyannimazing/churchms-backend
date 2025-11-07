<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <title>FaithSeeker - Email Verification</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=Open+Sans:wght@400;500;600&display=swap');
        
        body {
            font-family: 'Open Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #f8f9fa;
            color: #495057;
            margin: 0;
            padding: 0;
            line-height: 1.6;
        }
        a {
            color: #5c7cfa;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        a:hover {
            color: #3b5bdb;
        }
        .wrapper {
            width: 100%;
            background-color: #f8f9fa;
            padding: 40px 0;
        }
        .content {
            width: 100%;
            max-width: 600px;
            background-color: #ffffff;
            margin: 0 auto;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        .header {
            background-color: #ffffff;
            padding: 30px 20px;
            text-align: center;
            border-bottom: 1px solid #e9ecef;
        }
        .logo-icon {
            font-size: 48px;
            color: #5c7cfa;
            margin-bottom: 15px;
        }
        .logo-text {
            font-family: 'Playfair Display', serif;
            font-size: 24px;
            color: #343a40;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        .icon-section {
            text-align: center;
            padding: 30px 0;
            background-color: #f8f9fa;
        }
        .main-icon {
            font-size: 64px;
            color: #5c7cfa;
            margin-bottom: 20px;
        }
        .inner-body {
            padding: 40px;
        }
        h1 {
            font-family: 'Playfair Display', serif;
            font-size: 28px;
            font-weight: 600;
            color: #343a40;
            margin-top: 0;
            margin-bottom: 20px;
            text-align: center;
        }
        p {
            font-size: 16px;
            color: #495057;
            margin-bottom: 24px;
        }
        .button-primary {
            display: inline-block;
            padding: 14px 28px;
            background-color: #5c7cfa;
            color: #ffffff !important;
            border-radius: 6px;
            font-weight: 600;
            text-decoration: none;
            box-shadow: 0 2px 4px rgba(92, 124, 250, 0.2);
            transition: all 0.3s ease;
        }
        .button-primary:hover {
            background-color: #3b5bdb;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(92, 124, 250, 0.3);
        }
        .footer {
            text-align: center;
            font-size: 14px;
            color: #868e96;
            padding: 30px 20px;
            border-top: 1px solid #e9ecef;
        }
        .greeting {
            color: #5c7cfa;
            font-weight: 600;
        }
        .signature {
            color: #343a40;
            font-weight: 500;
        }
        .social-icons {
            margin-top: 20px;
        }
        .social-icons a {
            display: inline-block;
            margin: 0 10px;
            color: #868e96;
            font-size: 20px;
            transition: all 0.3s ease;
        }
        .social-icons a:hover {
            color: #5c7cfa;
            transform: translateY(-2px);
        }
        @media only screen and (max-width: 600px) {
            .inner-body { padding: 30px 20px !important; }
            .header { padding: 20px 15px !important; }
            h1 { font-size: 24px !important; }
            .main-icon { font-size: 48px !important; }
        }
    </style>
</head>
<body>
    <table class="wrapper" cellpadding="0" cellspacing="0">
        <tr>
            <td align="center">
                <table class="content" cellpadding="0" cellspacing="0">
                    <!-- Header with icon logo -->
                    <tr>
                        <td class="header" align="center">
                            <div class="logo-icon">
                                <i class="fas fa-church"></i>
                            </div>
                            <div class="logo-text">FaithSeeker</div>
                        </td>
                    </tr>

                    <!-- Icon section -->
                    <tr>
                        <td class="icon-section">
                            <div class="main-icon">
                                <i class="fas fa-envelope-circle-check"></i>
                            </div>
                        </td>
                    </tr>

                    <!-- Body content -->
                    <tr>
                        <td class="inner-body">
                            <h1>Confirm Your Email</h1>
                            
                            <p><span class="greeting">Dear {{ $notifiable->name ?? 'Valued Member' }},</span></p>
                            
                            <p>Welcome to FaithSeeker! We're blessed to have you join our church community management system.</p>
                            
                            <p>Please verify your email address to complete your registration and access all booking features:</p>
                            
                            <table align="center" cellpadding="0" cellspacing="0" style="margin: 32px 0;">
                                <tr>
                                    <td align="center">
                                        <a href="{{ $url }}" class="button-primary" target="_blank" rel="noopener">
                                            <i class="fas fa-check-circle" style="margin-right: 8px;"></i> Verify Email
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            
                            <p>This verification link expires in 60 minutes. If you didn't request this, please ignore this email.</p>
                            
                            <p class="signature">God bless,<br>The FaithSeeker Team</p>
                        </td>
                    </tr>

                    <!-- Subcopy -->
                    <tr>
                        <td>
                            <table class="subcopy" width="100%" cellpadding="0" cellspacing="0" role="presentation">
                                <tr>
                                    <td>
                                        {{ Illuminate\Mail\Markdown::parse("Having trouble with the button? Copy this link into your browser:\n" . $url) }}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td class="footer">
                            <div class="social-icons">
                                <a href="#"><i class="fab fa-facebook"></i></a>
                                <a href="#"><i class="fab fa-instagram"></i></a>
                                <a href="#"><i class="fab fa-youtube"></i></a>
                                <a href="#"><i class="fas fa-globe"></i></a>
                            </div>
                            © {{ date('Y') }} FaithSeeker Church Booking System<br>
                            <a href="mailto:support@faithseeker.app">Contact Support</a> | 
                            <a href="https://faithseeker.app/privacy">Privacy Policy</a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>