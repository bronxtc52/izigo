// Форматирование денег для веб-админки: центы → строка «$N.NN» (ru-локаль).
export const usd = (cents) =>
    `$${((cents ?? 0) / 100).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
