// Маршрут существует только ради редиректа в соседнем layout.js (→ /miniapp).
// Контент не рендерится: layout возвращает <RedirectToMiniApp/> и не отдаёт children.
// Старый web-кабинет/админка удалены (см. docs/specs/2026-06-21-dead-code-audit.md, F8).
export default function RedirectStub() {
    return null;
}
