<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Bienvenue sur Qayed</title>
  <style>
    body { margin: 0; padding: 0; background: #F3F4F6; font-family: 'Segoe UI', Arial, sans-serif; color: #1F2937; }
    .wrapper { max-width: 560px; margin: 40px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.06); }
    .header { background: #1B3A5F; padding: 32px 40px; text-align: center; }
    .header h1 { margin: 0; font-size: 22px; color: #ffffff; letter-spacing: -0.3px; }
    .header p  { margin: 6px 0 0; font-size: 13px; color: #9CB3CC; }
    .body { padding: 32px 40px; }
    .body p { font-size: 15px; line-height: 1.6; margin: 0 0 16px; }
    .cta { text-align: center; margin: 28px 0 8px; }
    .cta a { display: inline-block; background: #1B3A5F; color: #ffffff; text-decoration: none; padding: 12px 28px; border-radius: 8px; font-size: 14px; font-weight: 600; letter-spacing: 0.2px; }
    .warning { background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 8px; padding: 12px 16px; font-size: 13px; color: #92400E; margin-top: 24px; }
    .footer { padding: 20px 40px; text-align: center; font-size: 12px; color: #9CA3AF; border-top: 1px solid #F3F4F6; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>Bienvenue sur Qayed</h1>
      <p>Plateforme de gestion hôtelière</p>
    </div>
    <div class="body">
      <p>Bonjour <strong>{{ $firstName }} {{ $lastName }}</strong>,</p>
      <p>
        Un compte a été créé pour vous sur <strong>Qayed</strong> en tant que
        <strong>{{ $role === 'hotel_admin' ? 'Administrateur' : 'Réceptionniste' }}</strong>
        de l'établissement <strong>{{ $hotelName }}</strong>.
      </p>
      <p>Voici vos identifiants de connexion :</p>

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
        style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;margin:24px 0;">
        <tr>
          <td style="padding:14px 24px;border-bottom:1px solid #E2E8F0;font-size:12px;font-weight:600;color:#6B7280;text-transform:uppercase;letter-spacing:0.05em;">
            Email
          </td>
          <td align="right" style="padding:14px 24px;border-bottom:1px solid #E2E8F0;font-size:14px;font-weight:600;color:#1F2937;font-family:monospace;">
            {{ $email }}
          </td>
        </tr>
        <tr>
          <td style="padding:14px 24px;font-size:12px;font-weight:600;color:#6B7280;text-transform:uppercase;letter-spacing:0.05em;">
            Mot de passe temporaire
          </td>
          <td align="right" style="padding:14px 24px;font-size:14px;font-weight:600;color:#1F2937;font-family:monospace;">
            {{ $temporaryPassword }}
          </td>
        </tr>
      </table>

      <div class="cta">
        <a href="{{ $loginUrl }}">
          Se connecter →
        </a>
      </div>

      <div class="warning">
        ⚠️ &nbsp;Pour des raisons de sécurité, veuillez modifier votre mot de passe dès votre première connexion.
      </div>
    </div>
    <div class="footer">
      © {{ date('Y') }} Qayed — Tous droits réservés<br>
      Cet email a été envoyé automatiquement, merci de ne pas y répondre.
    </div>
  </div>
</body>
</html>
