import React, { useEffect, useState } from "react";

const CartModal = () => {
  const [isOpen, setIsOpen] = useState(false);

  useEffect(() => {
    const handleItemAdded = () => {
      setIsOpen(true);
    };

    // Listen for custom event
    document.addEventListener("cart:item-added", handleItemAdded);

    return () => {
      document.removeEventListener("cart:item-added", handleItemAdded);
    };
  }, []);

  const closeModal = () => setIsOpen(false);

  if (!isOpen) return null;

  return (
    <>
      <div
        className={`modal-overlay ${isOpen ? "show" : ""}`}
        onClick={closeModal}
      />
      <div className={`modal ${isOpen ? "show" : ""}`}>
        <div className="modal-content text-center">
          <button
            className="modal-close"
            onClick={closeModal}
            aria-label="Закрыть"
          >
            <svg
              viewBox="0 0 24 24"
              width="18"
              height="18"
              stroke="currentColor"
              strokeWidth="2.5"
              fill="none"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <line x1="18" y1="6" x2="6" y2="18"></line>
              <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
          </button>

          <div className="mb-6 flex justify-center text-emerald-500">
            <svg
              viewBox="0 0 24 24"
              width="64"
              height="64"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
              <polyline points="22 4 12 14.01 9 11.01"></polyline>
            </svg>
          </div>

          <h3 className="mb-2 text-2xl font-bold text-text-dark dark:text-darkmode-text-dark">
            Добавлено в корзину!
          </h3>
          <p className="mb-8 text-text-light dark:text-darkmode-text-light">
            Товар успешно добавлен в вашу корзину. Что вы хотите сделать далее?
          </p>

          <div className="flex flex-col gap-3">
            <a href="/cart" className="btn btn-primary w-full py-3 text-lg">
              Перейти в корзину
            </a>
            <button
              type="button"
              className="btn btn-outline-primary w-full py-3"
              onClick={closeModal}
            >
              Продолжить покупки
            </button>
          </div>
        </div>
      </div>
    </>
  );
};

export default CartModal;
