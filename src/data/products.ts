import { slugify } from "@/lib/utils/textConverter";

export const productCategories = [
  "Счетчики воды",
  "модули Nbiot",
  "модули gsm",
] as const;

export type ProductCategory = (typeof productCategories)[number];

export type Product = {
  id: string;
  title: string;
  category: ProductCategory;
  price: number;
  image: string;
  shortDescription: string;
  description: string;
  features: string[];
};

const rawProducts: Omit<Product, "id">[] = [
  {
    title: "Водомер SmartFlow W-15",
    category: "Счетчики воды",
    price: 149,
    image: "/images/service-1.png",
    shortDescription: "Умный счетчик для квартиры с точным учетом расхода.",
    description:
      "Компактный прибор для учета холодной и горячей воды. Поддерживает подключение внешнего модуля связи и удобен для поквартирного учета.",
    features: [
      "Диаметр подключения 1/2 дюйма",
      "Класс точности B",
      "Ресурс работы до 12 лет",
    ],
  },
  {
    title: "Водомер AquaMeter Pro 20",
    category: "Счетчики воды",
    price: 189,
    image: "/images/service-2.png",
    shortDescription: "Надежный счетчик для частного дома и небольших объектов.",
    description:
      "Модель с увеличенной пропускной способностью и устойчивостью к перепадам давления. Подходит для долгосрочной эксплуатации.",
    features: [
      "Диаметр подключения 3/4 дюйма",
      "Устойчивость к гидроударам",
      "Увеличенный межповерочный интервал",
    ],
  },
  {
    title: "NB-IoT модуль LinkNode N1",
    category: "модули Nbiot",
    price: 99,
    image: "/images/service-3.png",
    shortDescription: "Базовый NB-IoT модуль для удаленной передачи данных.",
    description:
      "Энергоэффективный коммуникационный модуль для телеметрии и автоматизированного сбора данных с приборов учета.",
    features: [
      "Поддержка NB-IoT Cat-NB1",
      "Низкое энергопотребление",
      "Интерфейсы UART и GPIO",
    ],
  },
  {
    title: "NB-IoT модуль LinkNode N2",
    category: "модули Nbiot",
    price: 129,
    image: "/images/call-to-action.png",
    shortDescription: "Расширенный NB-IoT модуль с повышенной стабильностью сигнала.",
    description:
      "Модуль для промышленных систем мониторинга. Обеспечивает стабильную связь в условиях плотной застройки.",
    features: [
      "Усиленный радиотракт",
      "Поддержка eDRX и PSM",
      "Монтаж на DIN-рейку через адаптер",
    ],
  },
  {
    title: "GSM модуль Connect G100",
    category: "модули gsm",
    price: 79,
    image: "/images/banner.png",
    shortDescription: "Универсальный GSM модуль для телеметрии и уведомлений.",
    description:
      "Решение для объектов, где доступна только GSM-сеть. Передает показания и аварийные события в диспетчерскую систему.",
    features: [
      "Поддержка 2G/GPRS",
      "SIM-слот стандартного размера",
      "Антенна в комплекте",
    ],
  },
  {
    title: "GSM модуль Connect G200",
    category: "модули gsm",
    price: 109,
    image: "/images/service-1.png",
    shortDescription: "Промышленный GSM модуль с расширенной диагностикой.",
    description:
      "Модель для распределенных объектов ЖКХ и промышленности с функцией автоматического восстановления соединения.",
    features: [
      "Удаленная диагностика устройства",
      "Сторожевой таймер",
      "Широкий диапазон рабочих температур",
    ],
  },
];

export const products: Product[] = rawProducts.map((product) => ({
  ...product,
  id: slugify(product.title),
}));

export const getProductById = (id: string) =>
  products.find((product) => product.id === id);
