import React, { useEffect, useState } from "react";
import { getCart, getCartTotal, clearCart } from "@/lib/cart";

const CheckoutModal = () => {
  const [isOpen, setIsOpen] = useState(false);
  const [step, setStep] = useState<"type" | "form" | "success">("type");
  const [checkoutType, setCheckoutType] = useState<"fiz" | "yur">("fiz");
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Form states
  const [formData, setFormData] = useState({
    fullName: "",
    phone: "",
    address: "",
    unp: "",
    orgName: "",
  });

  useEffect(() => {
    const handleOpen = () => {
      setIsOpen(true);
      setStep("type");
      setError(null);
    };

    document.addEventListener("checkout:open", handleOpen);
    return () => document.removeEventListener("checkout:open", handleOpen);
  }, []);

  const closeModal = () => {
    if (isSubmitting) return;
    setIsOpen(false);
  };

  const handleTypeSelect = (type: "fiz" | "yur") => {
    setCheckoutType(type);
    setStep("form");
  };

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
    const { name, value } = e.target;
    setFormData((prev) => ({ ...prev, [name]: value }));
  };

  const handleSubmit = async (e: React.SubmitEvent<HTMLFormElement>) => {
    e.preventDefault();
    setIsSubmitting(true);
    setError(null);

    const cart = getCart();
    const total = getCartTotal();

    if (cart.length === 0) {
      setError("Ваша корзина пуста");
      setIsSubmitting(false);
      return;
    }

    const payload = {
      requestType: "checkout",
      checkoutType,
      name: checkoutType === "fiz" ? formData.fullName : formData.orgName,
      phone: formData.phone,
      address: formData.address,
      unp: checkoutType === "yur" ? formData.unp : undefined,
      cartItems: cart,
      total: total,
    };

    try {
      const response = await fetch("/api/send-callback.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });

      const data = await response.json();

      if (data.success) {
        setStep("success");
        clearCart();
        // Trigger cart update event to refresh UI
        window.dispatchEvent(new CustomEvent("cart:update", { detail: [] }));
      } else {
        setError(data.error || "Произошла ошибка при отправке заказа");
      }
    } catch (err) {
      setError("Не удалось связаться с сервером");
    } finally {
      setIsSubmitting(false);
    }
  };

  if (!isOpen) return null;

  return (
    <>
      <div 
        className={`modal-overlay ${isOpen ? "show" : ""}`} 
        onClick={closeModal}
      />
      <div className={`modal ${isOpen ? "show" : ""}`}>
        <div className="modal-content w-[500px]!">
          <button 
            className="modal-close" 
            onClick={closeModal}
            aria-label="Закрыть"
            disabled={isSubmitting}
          >
            <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" strokeWidth="2.5" fill="none" strokeLinecap="round" strokeLinejoin="round">
              <line x1="18" y1="6" x2="6" y2="18"></line>
              <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
          </button>

          {step === "type" && (
            <div className="text-center">
              <h3 className="mb-6 text-2xl font-bold text-text-dark dark:text-darkmode-text-dark">
                Оформление заказа
              </h3>
              <p className="mb-8 text-text-light dark:text-darkmode-text-light">
                Выберите тип плательщика
              </p>
              <div className="grid grid-cols-2 gap-4">
                <button
                  onClick={() => handleTypeSelect("fiz")}
                  className="flex flex-col items-center justify-center rounded-xl border-2 border-border p-6 transition hover:border-primary hover:bg-primary/5 dark:border-darkmode-border dark:hover:border-darkmode-primary"
                >
                  <svg className="mb-3 text-primary dark:text-darkmode-primary" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                    <circle cx="12" cy="7" r="4"></circle>
                  </svg>
                  <span className="font-bold">Физлицо</span>
                </button>
                <button
                  onClick={() => handleTypeSelect("yur")}
                  className="flex flex-col items-center justify-center rounded-xl border-2 border-border p-6 transition hover:border-primary hover:bg-primary/5 dark:border-darkmode-border dark:hover:border-darkmode-primary"
                >
                  <svg className="mb-3 text-primary dark:text-darkmode-primary" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
                    <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
                  </svg>
                  <span className="font-bold">Юрлицо</span>
                </button>
              </div>
            </div>
          )}

          {step === "form" && (
            <div>
              <button 
                onClick={() => setStep("type")} 
                className="mb-4 flex items-center gap-1 text-sm text-text-light transition hover:text-primary dark:text-darkmode-text-light dark:hover:text-darkmode-primary"
              >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                  <line x1="19" y1="12" x2="5" y2="12"></line>
                  <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Назад
              </button>
              <h3 className="mb-6 text-xl font-bold text-text-dark dark:text-darkmode-text-dark">
                Данные для {checkoutType === "fiz" ? "физлица" : "юрлица"}
              </h3>
              
              <form onSubmit={handleSubmit} className="space-y-4">
                {checkoutType === "fiz" ? (
                  <>
                    <div>
                      <label className="mb-1 block text-sm font-medium">Фамилия Имя Отчество</label>
                      <input
                        required
                        type="text"
                        name="fullName"
                        value={formData.fullName}
                        onChange={handleInputChange}
                        className="w-full rounded-lg border border-border bg-transparent px-4 py-2 focus:border-primary focus:outline-none dark:border-darkmode-border"
                        placeholder="Иванов Иван Иванович"
                      />
                    </div>
                  </>
                ) : (
                  <>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                      <div>
                        <label className="mb-1 block text-sm font-medium">УНП</label>
                        <input
                          required
                          type="text"
                          name="unp"
                          value={formData.unp}
                          onChange={handleInputChange}
                          className="w-full rounded-lg border border-border bg-transparent px-4 py-2 focus:border-primary focus:outline-none dark:border-darkmode-border"
                          placeholder="123456789"
                        />
                      </div>
                      <div>
                        <label className="mb-1 block text-sm font-medium">Организация</label>
                        <input
                          required
                          type="text"
                          name="orgName"
                          value={formData.orgName}
                          onChange={handleInputChange}
                          className="w-full rounded-lg border border-border bg-transparent px-4 py-2 focus:border-primary focus:outline-none dark:border-darkmode-border"
                          placeholder='ООО "Компания"'
                        />
                      </div>
                    </div>
                  </>
                )}

                <div>
                  <label className="mb-1 block text-sm font-medium">Телефон</label>
                  <input
                    required
                    type="tel"
                    name="phone"
                    value={formData.phone}
                    onChange={handleInputChange}
                    className="w-full rounded-lg border border-border bg-transparent px-4 py-2 focus:border-primary focus:outline-none dark:border-darkmode-border"
                    placeholder="+375 (__) ___-__-__"
                  />
                </div>

                <div>
                  <label className="mb-1 block text-sm font-medium">Адрес установки</label>
                  <textarea
                    required
                    name="address"
                    value={formData.address}
                    onChange={handleInputChange}
                    className="w-full rounded-lg border border-border bg-transparent px-4 py-2 focus:border-primary focus:outline-none dark:border-darkmode-border"
                    rows={2}
                    placeholder="г. Минск, ул. Примерная, д. 1, кв. 1"
                  />
                </div>

                {error && (
                  <div className="text-sm text-red-500">
                    {error}
                  </div>
                )}

                <button
                  type="submit"
                  disabled={isSubmitting}
                  className="btn btn-primary w-full py-3 disabled:opacity-50"
                >
                  {isSubmitting ? "Отправка..." : "Подтвердить заказ"}
                </button>
              </form>
            </div>
          )}

          {step === "success" && (
            <div className="py-8 text-center">
              <div className="mb-6 flex justify-center text-emerald-500">
                <svg viewBox="0 0 24 24" width="64" height="64" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                  <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                  <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
              </div>
              <h3 className="mb-2 text-2xl font-bold text-text-dark dark:text-darkmode-text-dark">
                Заказ принят!
              </h3>
              <p className="mb-8 text-text-light dark:text-darkmode-text-light">
                Спасибо за заказ. Наш менеджер свяжется с вами в ближайшее время для уточнения деталей.
              </p>
              <button 
                onClick={closeModal} 
                className="btn btn-primary w-full py-3"
              >
                Закрыть
              </button>
            </div>
          )}
        </div>
      </div>
    </>
  );
};

export default CheckoutModal;
