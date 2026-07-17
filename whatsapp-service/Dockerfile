# MODULE PROVISOIRE — service émetteur WhatsApp Qayed (à retirer après homologation MI).
FROM node:20-slim

# Dépendances Chromium pour puppeteer / whatsapp-web.js en conteneur.
RUN apt-get update && apt-get install -y --no-install-recommends \
    chromium \
    ca-certificates \
    fonts-liberation \
    libnss3 libatk-bridge2.0-0 libgtk-3-0 libx11-xcb1 libxcomposite1 \
    libxdamage1 libxrandr2 libgbm1 libasound2 libpangocairo-1.0-0 \
 && rm -rf /var/lib/apt/lists/*

ENV PUPPETEER_SKIP_DOWNLOAD=1 \
    PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium

WORKDIR /app
COPY package.json package-lock.json* ./
# Les patches doivent être présents AVANT npm install : le postinstall lance
# patch-package, qui applique patches/whatsapp-web.js+1.34.7.patch (compat `$1`
# / envoi de médias contre la version live de WhatsApp Web).
COPY patches ./patches
RUN npm install --omit=dev
COPY . .

# Persistance de la session : monter un Railway Volume sur WHATSAPP_SESSION_PATH
# (ex. /app/.wwebjs_auth) via l'interface Railway. Ne PAS utiliser l'instruction
# Docker VOLUME ici — Railway ne la supporte pas et refuse le build.

EXPOSE 3001
CMD ["node", "index.js"]
