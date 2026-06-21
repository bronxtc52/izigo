# IziGo frontend (Next 14) — образ для Azure Container Apps. output: standalone.
# NEXT_PUBLIC_* запекаются на этапе build → передаём build-args.
FROM node:20-alpine AS deps
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci

FROM node:20-alpine AS builder
WORKDIR /app
COPY --from=deps /app/node_modules ./node_modules
COPY . .
ARG NEXT_PUBLIC_SERVER_BACK_URL
ARG NEXT_PUBLIC_SERVER_FRONT_URL
ARG NEXT_PUBLIC_SERVER_MAIN_PROJECT
ARG NEXT_PUBLIC_SERVER_PROD=true
ARG NEXT_PUBLIC_TON_NETWORK
ARG NEXT_PUBLIC_TON_RPC
ARG NEXT_PUBLIC_USDT_JETTON_MASTER
ARG NEXT_PUBLIC_TWA_RETURN_URL
RUN npm run build

FROM node:20-alpine AS runner
WORKDIR /app
ENV NODE_ENV=production
ENV PORT=3000
RUN addgroup -g 1001 -S nodejs && adduser -S nextjs -u 1001
COPY --from=builder /app/public ./public
COPY --from=builder --chown=nextjs:nodejs /app/.next/standalone ./
COPY --from=builder --chown=nextjs:nodejs /app/.next/static ./.next/static
USER nextjs
EXPOSE 3000
CMD ["node", "server.js"]
