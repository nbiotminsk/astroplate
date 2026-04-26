export interface CartItem {
  id: string;
  title: string;
  price: number;
  quantity: number;
  image?: string;
  category?: string;
}

export const CART_KEY = "catalog-cart-v1";
export const CART_UPDATE_EVENT = "cart:update";

export const getCart = (): CartItem[] => {
  if (typeof window === "undefined") return [];
  try {
    return JSON.parse(localStorage.getItem(CART_KEY) || "[]") as CartItem[];
  } catch {
    return [];
  }
};

export const saveCart = (cart: CartItem[]) => {
  if (typeof window === "undefined") return;
  localStorage.setItem(CART_KEY, JSON.stringify(cart));
  window.dispatchEvent(new CustomEvent(CART_UPDATE_EVENT, { detail: cart }));
};

export const addToCart = (product: CartItem) => {
  const cart = getCart();
  const existing = cart.find((item) => item.id === product.id);

  if (existing) {
    existing.quantity += product.quantity;
  } else {
    cart.push(product);
  }

  saveCart(cart);
};

export const removeFromCart = (id: string) => {
  const cart = getCart();
  const nextCart = cart.filter((item) => item.id !== id);
  saveCart(nextCart);
};

export const updateQuantity = (id: string, quantity: number) => {
  const cart = getCart();
  const item = cart.find((item) => item.id === id);
  if (item) {
    item.quantity = Math.max(1, quantity);
    saveCart(cart);
  }
};

export const clearCart = () => {
  saveCart([]);
};

export const getCartCount = (): number => {
  const cart = getCart();
  return cart.reduce((sum, item) => sum + item.quantity, 0);
};

export const getCartTotal = (): number => {
  const cart = getCart();
  return cart.reduce((sum, item) => sum + item.price * item.quantity, 0);
};
