import { slugify } from "@/lib/utils/textConverter";

export const productCategories = [
  "Счетчики воды",
  "модули Nbiot",
  "модули gsm",
  "комплекты",
  "услуги",
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
  sku: string;
  mpn: string;
  brand: string;
  availability: "https://schema.org/InStock" | "https://schema.org/OutOfStock";
  condition:
    | "https://schema.org/NewCondition"
    | "https://schema.org/UsedCondition"
    | "https://schema.org/DamagedCondition"
    | "https://schema.org/RefurbishedCondition";
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
    sku: "SF-W15",
    mpn: "SF-W15",
    brand: "Teleofis24",
    availability: "https://schema.org/InStock",
    condition: "https://schema.org/NewCondition",
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
    sku: "AMP-20",
    mpn: "AMP-20",
    brand: "Teleofis24",
    availability: "https://schema.org/InStock",
    condition: "https://schema.org/NewCondition",
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
    sku: "LN-N1",
    mpn: "LN-N1",
    brand: "Teleofis24",
    availability: "https://schema.org/InStock",
    condition: "https://schema.org/NewCondition",
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
    sku: "LN-N2",
    mpn: "LN-N2",
    brand: "Teleofis24",
    availability: "https://schema.org/InStock",
    condition: "https://schema.org/NewCondition",
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
    sku: "CG-G100",
    mpn: "CG-G100",
    brand: "Teleofis24",
    availability: "https://schema.org/InStock",
    condition: "https://schema.org/NewCondition",
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
    sku: "CG-G200",
    mpn: "CG-G200",
    brand: "Teleofis24",
    availability: "https://schema.org/InStock",
    condition: "https://schema.org/NewCondition",
  },
  {
    title: "Модуль NBIOT + 2 счетчика воды 15 мм",
    category: "комплекты",
    price: 329,
    image: "/images/service-2.png",
    shortDescription:
      "Готовый комплект для установки в квартире или небольшом офисе.",
    description:
      "Комплект включает модуль NBIOT и два счетчика воды 15 мм для холодной и горячей линии. Подходит для быстрого запуска системы удаленного учета.",
    features: [
      "2 счетчика воды Ду 15 мм",
      "1 модуль связи NBIOT",
      "Готов к монтажу и подключению",
    ],
    sku: "KIT-NBIOT-2W15",
    mpn: "KIT-NBIOT-2W15",
    brand: "Teleofis24",
    availability: "https://schema.org/InStock",
    condition: "https://schema.org/NewCondition",
  },
  {
    title: "Модуль NBIoT + счетчик воды Ду 20 мм",
    category: "комплекты",
    price: 259,
    image: "/images/service-3.png",
    shortDescription: "Комплект для объектов с повышенным расходом воды.",
    description:
      "Решение для частных домов и коммерческих помещений: модуль NBIoT и счетчик Ду 20 мм с возможностью удаленной передачи данных.",
    features: [
      "Счетчик воды Ду 20 мм",
      "Модуль NBIoT",
      "Удобная интеграция в систему диспетчеризации",
    ],
    sku: "KIT-NBIOT-W20",
    mpn: "KIT-NBIOT-W20",
    brand: "Teleofis24",
    availability: "https://schema.org/InStock",
    condition: "https://schema.org/NewCondition",
  },
  {
    title: "Замена счетчика воды Ду 15-25 мм",
    category: "услуги",
    price: 85,
    image: "/images/call-to-action.png",
    shortDescription: "Демонтаж старого и монтаж нового счетчика воды.",
    description:
      "Комплексная услуга по замене счетчиков воды Ду 15-25 мм с проверкой герметичности и подготовкой к дальнейшей эксплуатации.",
    features: [
      "Выезд специалиста на объект",
      "Снятие старого прибора учета",
      "Монтаж и базовая проверка работы",
    ],
    sku: "SERVICE-REPLACE-15-25",
    mpn: "SERVICE-REPLACE-15-25",
    brand: "Teleofis24",
    availability: "https://schema.org/InStock",
    condition: "https://schema.org/NewCondition",
  },
  {
    title: "Поверка счетчика воды Ду 15-25 мм",
    category: "услуги",
    price: 65,
    image: "/images/banner.png",
    shortDescription: "Поверка с оформлением необходимых документов.",
    description:
      "Проводим поверку счетчиков воды Ду 15-25 мм в соответствии с действующими требованиями и предоставляем результаты для отчетности.",
    features: [
      "Проверка точности измерений",
      "Оформление документов по поверке",
      "Консультация по срокам следующей поверки",
    ],
    sku: "SERVICE-CALIBRATION-15-25",
    mpn: "SERVICE-CALIBRATION-15-25",
    brand: "Teleofis24",
    availability: "https://schema.org/InStock",
    condition: "https://schema.org/NewCondition",
  },
  {
    title: "Ремонт и обслуживание модуля Zeta Тахат",
    category: "услуги",
    price: 120,
    image: "/images/service-1.png",
    shortDescription:
      "Диагностика, ремонт и регламентное обслуживание модулей Zeta Тахат.",
    description:
      "Выполняем восстановление работоспособности модуля Zeta Тахат, профилактику и обновление базовых параметров для стабильной связи.",
    features: [
      "Полная диагностика модуля",
      "Замена неисправных элементов",
      "Тестирование после обслуживания",
    ],
    sku: "SERVICE-ZETA-REPAIR",
    mpn: "SERVICE-ZETA-REPAIR",
    brand: "Teleofis24",
    availability: "https://schema.org/InStock",
    condition: "https://schema.org/NewCondition",
  },
];

export const products: Product[] = rawProducts.map((product) => ({
  ...product,
  id: slugify(product.title),
}));

export const getProductById = (id: string) =>
  products.find((product) => product.id === id);
