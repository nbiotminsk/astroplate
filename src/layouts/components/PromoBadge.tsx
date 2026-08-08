import { useState, useEffect } from "react";

export default function PromoBadge() {
  const [isOpen, setIsOpen] = useState(false);
  const [isBouncing, setIsBouncing] = useState(true);

  useEffect(() => {
    if (isOpen) {
      document.body.style.overflow = "hidden";
    } else {
      document.body.style.overflow = "";
    }
    return () => {
      document.body.style.overflow = "";
    };
  }, [isOpen]);

  useEffect(() => {
    const timer = setTimeout(() => setIsBouncing(false), 10000);
    return () => clearTimeout(timer);
  }, []);

  return (
    <>
      <button
        onClick={() => setIsOpen(true)}
        className={`fixed bottom-6 left-6 md:right-6 md:left-auto z-40 flex items-center gap-2 rounded-full bg-primary px-5 py-3 text-sm font-bold text-white shadow-lg shadow-primary/30 transition-all hover:scale-105 hover:bg-primary/90 hover:shadow-xl active:scale-95 ${
          isBouncing ? "animate-bounce" : ""
        }`}
        aria-label="Что мне делать?"
      >
        <span className="text-base">❓</span>
        <span>Что мне делать ?</span>
        <span className="absolute -top-1 -right-1 flex h-3 w-3">
          <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-400 opacity-75" />
          <span className="relative inline-flex h-3 w-3 rounded-full bg-amber-400" />
        </span>
      </button>

      {isOpen && (
        <div
          className="fixed inset-0 z-[100] flex items-center justify-center bg-black/60 p-4 backdrop-blur-xs"
          onClick={(e) => {
            if (e.target === e.currentTarget) setIsOpen(false);
          }}
        >
          <div className="relative w-full max-w-lg max-h-[85vh] overflow-y-auto animate-[fadeInScale_0.2s_ease-out] rounded-2xl bg-white p-6 sm:p-7 shadow-2xl dark:bg-darkmode-body border border-border dark:border-darkmode-border">
            <button
              onClick={() => setIsOpen(false)}
              className="absolute top-4 right-4 flex h-8 w-8 items-center justify-center rounded-full text-text-light transition-colors hover:bg-light hover:text-text-dark dark:hover:bg-darkmode-light dark:text-darkmode-text-light"
              aria-label="Закрыть"
            >
              ✕
            </button>

            <div className="mb-5 pr-6">
              <span className="inline-block rounded-full bg-primary/10 px-3 py-1 text-xs font-bold text-primary dark:bg-primary/20 dark:text-primary mb-2">
                Памятка потребителю
              </span>
              <h3 className="text-2xl font-bold text-text-dark dark:text-white leading-tight">
                Пошаговая инструкция
              </h3>
              <p className="mt-2 text-sm text-text-light dark:text-darkmode-text-light leading-relaxed font-medium">
                Что нужно знать и сделать, чтобы исполнить постановление 788 и данные попадали в УП «Минскводоканал».
              </p>
            </div>

            <div className="space-y-4 mb-6">
              {/* Step 1 */}
              <div className="flex gap-3.5 items-start p-3.5 rounded-xl bg-light dark:bg-darkmode-light border border-border/60 dark:border-darkmode-border/60">
                <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-bold text-white shadow-xs">
                  1
                </span>
                <p className="text-sm text-text-dark dark:text-white leading-relaxed">
                  <strong>Узнайте в Водоканале</strong>, относитесь ли вы к юрлицам или физлицам — от этого зависит выбор оборудования и платформы.
                </p>
              </div>

              {/* Step 2 */}
              <div className="flex gap-3.5 items-start p-3.5 rounded-xl bg-light dark:bg-darkmode-light border border-border/60 dark:border-darkmode-border/60">
                <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-bold text-white shadow-xs">
                  2
                </span>
                <p className="text-sm text-text-dark dark:text-white leading-relaxed">
                  <strong>Убедитесь, что ваши счётчики воды имеют импульсный выход (провод).</strong> Если нет — придётся купить новые счётчики с импульсным выходом, их цена начинается от 70 рублей. Посмотрите, как выглядят такие счётчики, в разделе{" "}
                  <a
                    href="/#meters"
                    onClick={() => setIsOpen(false)}
                    className="font-bold text-primary underline underline-offset-2 hover:opacity-80 transition-opacity"
                  >
                    Счётчики воды
                  </a>.
                </p>
              </div>

              {/* Step 3 */}
              <div className="flex gap-3.5 items-start p-3.5 rounded-xl bg-light dark:bg-darkmode-light border border-border/60 dark:border-darkmode-border/60">
                <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-bold text-white shadow-xs">
                  3
                </span>
                <p className="text-sm text-text-dark dark:text-white leading-relaxed">
                  <strong>Купите модуль NB-IoT и SIM-карту</strong> для передачи данных.
                </p>
              </div>

              {/* Step 4 */}
              <div className="flex gap-3.5 items-start p-3.5 rounded-xl bg-light dark:bg-darkmode-light border border-border/60 dark:border-darkmode-border/60">
                <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-bold text-white shadow-xs">
                  4
                </span>
                <p className="text-sm text-text-dark dark:text-white leading-relaxed">
                  <strong>Чтобы показания где-то хранились и их мог читать Водоканал</strong>, модуль нужно настроить на платформу по сбору и учёту данных: UNICBOARD, «Мой Клиент : Ресурсы» или платформа «А1». Для физлиц подходит только одна — UNICBOARD, которая идёт в комплекте с модулем Nero 2576.
                </p>
              </div>
            </div>

            <div className="flex flex-col sm:flex-row gap-3">
              <button
                type="button"
                onClick={() => {
                  setIsOpen(false);
                  window.dispatchEvent(new CustomEvent("order-modal:open"));
                }}
                className="btn btn-primary w-full py-3 text-sm font-bold text-center shadow-md justify-center"
              >
                Заказать под ключ
              </button>
              <a
                href="tel:+375298462462"
                className="btn border border-border dark:border-darkmode-border bg-light dark:bg-darkmode-light text-text-dark dark:text-white hover:bg-border/40 py-3 text-sm font-bold text-center w-full justify-center"
              >
                📞 +375 29 8-462-462
              </a>
            </div>
          </div>
        </div>
      )}

      <style>{`
        @keyframes fadeInScale {
          from { opacity: 0; transform: scale(0.95); }
          to { opacity: 1; transform: scale(1); }
        }
      `}</style>
    </>
  );
}
