<!doctype html>
<html lang="bs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MAHALA kod za promjenu lozinke</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background: #140d22;
            color: #f8f4ff;
            font-family: Arial, Helvetica, sans-serif;
        }

        .shell {
            width: 100%;
            padding: 34px 14px;
            box-sizing: border-box;
            background:
                radial-gradient(circle at 20% 0%, rgba(139, 92, 246, 0.34), transparent 34%),
                radial-gradient(circle at 86% 20%, rgba(236, 72, 153, 0.22), transparent 32%),
                #140d22;
        }

        .card {
            max-width: 520px;
            margin: 0 auto;
            overflow: hidden;
            border-radius: 22px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            background: #21123a;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.32);
        }

        .hero {
            padding: 30px 28px 22px;
            background: linear-gradient(135deg, #3b1f66 0%, #7c3aed 58%, #ec4899 100%);
        }

        .brand {
            margin: 0 0 18px;
            color: #ffffff;
            font-size: 13px;
            font-weight: 900;
            letter-spacing: 2px;
        }

        h1 {
            margin: 0;
            color: #ffffff;
            font-size: 28px;
            line-height: 1.15;
            font-weight: 900;
        }

        .content {
            padding: 28px;
        }

        p {
            margin: 0 0 18px;
            color: #d8cdef;
            font-size: 15px;
            line-height: 1.55;
        }

        .code {
            margin: 22px 0;
            padding: 20px;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.14);
            color: #ffffff;
            font-size: 42px;
            line-height: 1;
            font-weight: 900;
            letter-spacing: 12px;
            text-align: center;
        }

        .note {
            margin: 18px 0 0;
            color: #a99bc4;
            font-size: 12px;
            line-height: 1.5;
        }

        .footer {
            padding: 18px 28px 26px;
            color: #817394;
            font-size: 11px;
            line-height: 1.45;
        }
    </style>
</head>
<body>
    <div class="shell">
        <div class="card">
            <div class="hero">
                <p class="brand">MAHALA</p>
                <h1>Promijeni lozinku za svoj MAHALA racun.</h1>
            </div>
            <div class="content">
                <p>Unesi ovaj kod u aplikaciji da nastavis promjenu lozinke:</p>
                <div class="code">{{ $code }}</div>
                <p>Kod vrijedi {{ $expiresInMinutes }} minuta. Ako nisi zatrazio promjenu lozinke, ovu poruku mozes ignorisati.</p>
                <p class="note">Zbog sigurnosti ne dijeli ovaj kod ni sa kim.</p>
            </div>
            <div class="footer">
                Poslano automatski sa MAHALA aplikacije. Odgovori na ovaj email se ne prate.
            </div>
        </div>
    </div>
</body>
</html>
