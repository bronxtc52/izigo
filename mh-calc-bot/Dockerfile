# IziGo Telegram bot worker (grammY). Long-polling, без ingress.
FROM node:20-alpine

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm ci --omit=dev || npm install --omit=dev

COPY src ./src

# Токен бота тянется из Key Vault через managed identity в рантайме (не из env).
ENV NODE_ENV=production
CMD ["node", "src/index.js"]
