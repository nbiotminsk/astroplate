const STORE_LABELS = ["premium", "discount", "standart"] as const;

export type StoreLabel = (typeof STORE_LABELS)[number] | string;

export const normalizeStoreLabel = (label?: unknown): StoreLabel => {
  if (typeof label === "string") {
    return label.trim().toLowerCase() || "standart";
  }

  return "standart";
};

export const getStoreLabelPriority = (label?: unknown) => {
  const normalizedLabel = normalizeStoreLabel(label);

  if (normalizedLabel === "premium") return 0;
  if (normalizedLabel === "discount") return 1;
  return 2;
};

export const getStoreLabelMeta = (label?: unknown) => {
  const normalizedLabel = normalizeStoreLabel(label);

  if (normalizedLabel === "premium") {
    return {
      badge: "Премиум предложение",
      className:
        "border-amber-300 bg-gradient-to-r from-amber-50 to-white text-amber-900 dark:border-amber-500/50 dark:from-amber-500/15 dark:to-darkmode-light dark:text-amber-100",
    };
  }

  if (normalizedLabel === "discount") {
    return {
      badge: "Скидка",
      className:
        "border-rose-300 bg-rose-50 text-rose-900 dark:border-rose-500/50 dark:bg-rose-500/15 dark:text-rose-100",
    };
  }

  return null;
};
