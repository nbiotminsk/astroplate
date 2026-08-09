import React, { useEffect, useState } from "react";

const OrderModal: React.FC = () => {
  const [isOpen, setIsOpen] = useState(false);
  const [clientType, setClientType] = useState<"fiz" | "yur">("fiz");
  const [operatorCode, setOperatorCode] = useState<"29" | "33" | "44">("29");
  const [phoneDigits, setPhoneDigits] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isSuccess, setIsSuccess] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const handleOpen = (e: CustomEvent | Event) => {
      const customEv = e as CustomEvent;
      if (customEv?.detail?.type === "yur") {
        setClientType("yur");
      } else if (customEv?.detail?.type === "fiz") {
        setClientType("fiz");
      }
      setIsOpen(true);
      setIsSuccess(false);
      setError(null);
    };

    window.addEventListener("order-modal:open" as any, handleOpen);

    // Global click delegate for links/buttons with text or href matching target triggers
    const handleGlobalClick = (e: MouseEvent) => {
      const target = e.target as HTMLElement | null;
      if (!target) return;

      const triggerEl = target.closest(
        'a[href*="t.me/teleofis_by"], [data-open-order-modal], button.js-order-trigger, a.js-order-trigger',
      );

      if (triggerEl) {
        e.preventDefault();
        e.stopPropagation();
        const text = triggerEl.textContent?.toLowerCase() || "";
        if (text.includes("счет") || text.includes("счёт") || text.includes("юрлиц")) {
          setClientType("yur");
        } else {
          setClientType("fiz");
        }
        setIsOpen(true);
        setIsSuccess(false);
        setError(null);
      }
    };

    document.addEventListener("click", handleGlobalClick, true);

    return () => {
      window.removeEventListener("order-modal:open" as any, handleOpen);
      document.removeEventListener("click", handleGlobalClick, true);
    };
  }, []);

  const closeModal = () => {
    if (isSubmitting) return;
    setIsOpen(false);
  };

  const handlePhoneInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    // Keep only numeric characters 0-9 and limit to 7 digits
    const cleaned = e.target.value.replace(/\D/g, "").slice(0, 7);
    setPhoneDigits(cleaned);
    setError(null);
  };

  const handleSubmit = async (e: React.SyntheticEvent<HTMLFormElement>) => {
    e.preventDefault();
    setError(null);

    if (phoneDigits.length !== 7) {
      setError("Введите 7 цифр номера телефона");
      return;
    }

    const fullPhone = `+375${operatorCode}${phoneDigits}`;
    setIsSubmitting(true);

    try {
      const res = await fetch("/api/send-callback.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          requestType: "callback",
          checkoutType: clientType,
          phone: fullPhone,
        }),
      });

      const data = await res.json();
      if (!res.ok || data.error) {
        throw new Error(data.error || "Не удалось отправить заявку");
      }

      setIsSuccess(true);
      setPhoneDigits("");
    } catch (err: any) {
      setError(err.message || "Ошибка отправки. Попробуйте еще раз.");
    } finally {
      setIsSubmitting(false);
    }
  };

  if (!isOpen) return <div id="order-modal-root" />;

  return (
    <>
      <div
        className={`modal-overlay ${isOpen ? "show" : ""}`}
        onClick={closeModal}
      />
      <div className={`modal ${isOpen ? "show" : ""}`}>
        <div className="modal-content relative max-w-[92%] sm:max-w-120 w-full max-h-[90vh] overflow-y-auto p-5 sm:p-7 rounded-2xl bg-white dark:bg-darkmode-body border border-border dark:border-darkmode-border shadow-2xl">
          <button
            type="button"
            className="modal-close"
            onClick={closeModal}
            aria-label="Закрыть"
          >
            ✕
          </button>

          {isSuccess ? (
            <div className="text-center py-4 sm:py-6 space-y-4">
              <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400">
                <svg
                  className="h-8 w-8"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    strokeWidth={2}
                    d="M5 13l4 4L19 7"
                  />
                </svg>
              </div>
              <h3 className="text-xl font-bold text-text-dark dark:text-white">
                Заявка отправлена!
              </h3>
              <p className="text-sm text-text-light dark:text-darkmode-text-light">
                Спасибо! Мы свяжемся с вами по номеру +375 ({operatorCode}){" "}
                {phoneDigits || "..."} в ближайшее время.
              </p>
              <button
                type="button"
                onClick={closeModal}
                className="btn btn-primary w-full mt-4"
              >
                Отлично
              </button>
            </div>
          ) : (
            <div>
              <h2 className="text-xl sm:text-2xl font-bold mb-4 pr-6 text-text-dark dark:text-white text-center">
                Заказать услугу
              </h2>

              {/* Контакты компании */}
              <div className="mb-5 rounded-xl bg-light p-3.5 sm:p-4 dark:bg-darkmode-light border border-border/60 dark:border-darkmode-border/60 text-xs sm:text-sm space-y-2.5">
                <div className="flex flex-wrap items-center gap-1.5 sm:gap-2">
                  <span className="text-emerald-600 dark:text-emerald-400 font-bold shrink-0">
                    📞 Телефон:
                  </span>
                  <a
                    href="tel:+375298462462"
                    className="font-semibold text-text-dark dark:text-white hover:text-emerald-600 dark:hover:text-emerald-400 transition-colors"
                  >
                    +375 29 8-462-462
                  </a>
                </div>
                <div className="flex flex-wrap items-center gap-1.5 sm:gap-2">
                  <span className="text-emerald-600 dark:text-emerald-400 font-bold shrink-0">
                    ✉️ Почта:
                  </span>
                  <a
                    href="mailto:info@teleofis24.by"
                    className="font-semibold text-text-dark dark:text-white hover:text-emerald-600 dark:hover:text-emerald-400 transition-colors break-all"
                  >
                    info@teleofis24.by
                  </a>
                </div>
                <div className="flex flex-wrap items-center gap-1.5 sm:gap-2">
                  <span className="text-emerald-600 dark:text-emerald-400 font-bold shrink-0">
                    💬 Telegram:
                  </span>
                  <a
                    href="https://t.me/teleofis_by"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="font-semibold text-emerald-600 dark:text-emerald-400 underline underline-offset-2 hover:text-emerald-700 dark:hover:text-emerald-300 transition-colors break-all"
                  >
                    t.me/teleofis_by
                  </a>
                </div>
              </div>

              {/* Форма перезвоните мне */}
              <form onSubmit={handleSubmit} className="space-y-5">
                <div>
                  <label className="block text-sm font-semibold mb-2 text-text-dark dark:text-white">
                    Кто вы?
                  </label>
                  <div className="grid grid-cols-2 gap-3">
                    <button
                      type="button"
                      onClick={() => setClientType("fiz")}
                      className={`py-2.5 px-4 rounded-xl border text-sm font-medium transition-all ${
                        clientType === "fiz"
                          ? "border-emerald-600 bg-emerald-50 text-emerald-700 dark:border-emerald-500 dark:bg-emerald-950/40 dark:text-emerald-300 font-bold ring-2 ring-emerald-500/20"
                          : "border-border text-text dark:border-darkmode-border dark:text-darkmode-text hover:bg-light dark:hover:bg-darkmode-light"
                      }`}
                    >
                      👤 Физлицо
                    </button>
                    <button
                      type="button"
                      onClick={() => setClientType("yur")}
                      className={`py-2.5 px-4 rounded-xl border text-sm font-medium transition-all ${
                        clientType === "yur"
                          ? "border-emerald-600 bg-emerald-50 text-emerald-700 dark:border-emerald-500 dark:bg-emerald-950/40 dark:text-emerald-300 font-bold ring-2 ring-emerald-500/20"
                          : "border-border text-text dark:border-darkmode-border dark:text-darkmode-text hover:bg-light dark:hover:bg-darkmode-light"
                      }`}
                    >
                      🏢 Юрлицо
                    </button>
                  </div>
                </div>

                <div>
                  <label className="block text-sm font-semibold mb-2 text-text-dark dark:text-white">
                    Перезвоните мне
                  </label>
                  <div className="flex items-center gap-2">
                    <span className="shrink-0 rounded-xl bg-light px-3 py-2.5 font-semibold text-text-dark dark:bg-darkmode-light dark:text-white border border-border dark:border-darkmode-border text-sm">
                      +375
                    </span>
                    <select
                      value={operatorCode}
                      onChange={(e) =>
                        setOperatorCode(e.target.value as "29" | "33" | "44")
                      }
                      className="shrink-0 rounded-xl bg-light px-3 py-2.5 font-semibold text-text-dark dark:bg-darkmode-light dark:text-white border border-border dark:border-darkmode-border text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500"
                    >
                      <option value="29">29</option>
                      <option value="33">33</option>
                      <option value="44">44</option>
                    </select>
                    <input
                      type="tel"
                      inputMode="numeric"
                      pattern="[0-9]*"
                      maxLength={7}
                      placeholder="XXXXXXX"
                      value={phoneDigits}
                      onChange={handlePhoneInputChange}
                      className="w-full rounded-xl bg-light px-3.5 py-2.5 font-mono text-sm text-text-dark dark:bg-darkmode-light dark:text-white border border-border dark:border-darkmode-border focus:outline-none focus:ring-2 focus:ring-emerald-500"
                      required
                    />
                  </div>
                  <p className="mt-1 text-xs text-text-light dark:text-darkmode-text-light">
                    Введите 7 цифр номера (например: 8462462)
                  </p>
                </div>

                {error && (
                  <div className="rounded-lg bg-red-50 p-3 text-xs text-red-600 dark:bg-red-950/40 dark:text-red-400">
                    {error}
                  </div>
                )}

                <button
                  type="submit"
                  disabled={isSubmitting || phoneDigits.length !== 7}
                  className="btn btn-primary w-full py-3 text-base font-semibold disabled:opacity-50 disabled:cursor-not-allowed shadow-md"
                >
                  {isSubmitting ? "Отправка..." : "Перезвоните мне"}
                </button>
              </form>
            </div>
          )}
        </div>
      </div>
    </>
  );
};

export default OrderModal;
