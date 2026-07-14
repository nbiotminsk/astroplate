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
        className={`fixed bottom-6 left-6 md:right-6 md:left-auto z-50 flex items-center gap-2 rounded-full bg-red-600 px-5 py-3 text-sm font-bold text-white shadow-lg shadow-red-600/30 transition-all hover:scale-110 hover:bg-red-700 hover:shadow-xl active:scale-95 ${
          isBouncing ? "animate-bounce" : ""
        }`}
        aria-label="Открыть акцию"
      >
        <span className="text-lg">%</span>
        <span>АКЦИЯ</span>
        <span className="absolute -top-1 -right-1 flex h-3 w-3">
          <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-yellow-400 opacity-75" />
          <span className="relative inline-flex h-3 w-3 rounded-full bg-yellow-400" />
        </span>
      </button>

      {isOpen && (
        <div
          className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm"
          onClick={(e) => {
            if (e.target === e.currentTarget) setIsOpen(false);
          }}
        >
          <div className="relative w-full max-w-md animate-[fadeInScale_0.2s_ease-out] rounded-2xl bg-white p-6 shadow-2xl dark:bg-gray-900">
            <button
              onClick={() => setIsOpen(false)}
              className="absolute top-3 right-3 flex h-8 w-8 items-center justify-center rounded-full text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800"
              aria-label="Закрыть"
            >
              ✕
            </button>

            <div className="mb-4 flex items-center gap-3">
              <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-red-100 text-2xl dark:bg-red-900/30">
                📡
              </div>
              <div>
                <h3 className="text-xl font-bold text-gray-900 dark:text-white">
                  Аренда модуля NB-IoT
                </h3>
                <p className="text-sm text-gray-500 dark:text-gray-400">
                  Дистанционный съём показаний
                </p>
              </div>
            </div>

            <div className="mb-4 rounded-xl bg-gradient-to-br from-red-50 to-orange-50 p-4 dark:from-red-900/20 dark:to-orange-900/20">
              <div className="mb-1 text-sm text-gray-600 dark:text-gray-300">
                от
              </div>
              <div className="flex items-baseline gap-1">
                <span className="text-4xl font-extrabold text-red-600">
                  40
                </span>
                <span className="text-lg font-semibold text-red-600">
                  BYN/мес
                </span>
              </div>
              <div className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                для юрлиц · 45 BYN/мес для физлиц · залог 100 BYN
              </div>
            </div>

            <ul className="mb-5 space-y-2 text-sm text-gray-700 dark:text-gray-300">
              {[
                "Модуль + батарейка (до 5 лет)",
                "SIM-карта МТС NB-IoT",
                "Выезд и монтаж специалиста",
                "Подключение к «Мой Клиент: Ресурсы»",
                "Акт ввода для Минскводоканала",
                "Можно сдать в любой момент",
              ].map((item) => (
                <li key={item} className="flex items-start gap-2">
                  <span className="mt-0.5 text-green-500">✓</span>
                  <span>{item}</span>
                </li>
              ))}
            </ul>

            <div className="flex flex-col gap-2">
              <a
                href="/store/arenda-modulya-nbiot/"
                className="block rounded-xl bg-red-600 py-3 text-center text-sm font-bold text-white transition-colors hover:bg-red-700"
              >
                Подробнее об услуге
              </a>
              <a
                href="tel:+375298462462"
                className="block rounded-xl border border-gray-200 py-3 text-center text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
              >
                +375 29 8-462-462
              </a>
            </div>
          </div>
        </div>
      )}

      <style>{`
        @keyframes fadeInScale {
          from { opacity: 0; transform: scale(0.9); }
          to { opacity: 1; transform: scale(1); }
        }
      `}</style>
    </>
  );
}
