import type { CollectionEntry, CollectionKey } from "astro:content";
import { getBuildDate } from "@/lib/utils/buildTimestamp";

type ContentData = Record<string, any>;

const COMMON_DEFAULTS = {
  draft: false,
  description: "",
};

const COLLECTION_DEFAULTS: Partial<
  Record<CollectionKey, Record<string, unknown>>
> = {
  blog: {
    author: "Admin",
    categories: ["others"],
    tags: ["others"],
  },
  store: {
    availability: true,
    category: "others",
    custom_label_0: "standart",
    price: "0",
    price_currency: "BYN",
  },
};

export const resolveDate = (date?: Date | string | number | null): Date => {
  if (date instanceof Date && !Number.isNaN(date.valueOf())) {
    return date;
  }

  if (typeof date === "string" || typeof date === "number") {
    const parsedDate = new Date(date);
    if (!Number.isNaN(parsedDate.valueOf())) {
      return parsedDate;
    }
  }

  return getBuildDate();
};

const resolveCollectionDefaults = <C extends CollectionKey>(
  collectionName: C,
) => COLLECTION_DEFAULTS[collectionName] ?? {};

export const normalizeCollectionData = <C extends CollectionKey>(
  collectionName: C,
  data: ContentData,
) => {
  const defaults = resolveCollectionDefaults(collectionName);

  return {
    ...COMMON_DEFAULTS,
    ...defaults,
    ...data,
    draft: typeof data.draft === "boolean" ? data.draft : false,
    description:
      typeof data.description === "string"
        ? data.description
        : COMMON_DEFAULTS.description,
    date: resolveDate(data.date),
  };
};

export const normalizeCollectionEntry = <C extends CollectionKey>(
  collectionName: C,
  entry: CollectionEntry<C>,
): CollectionEntry<C> =>
  ({
    ...entry,
    data: normalizeCollectionData(collectionName, entry.data as ContentData),
  }) as CollectionEntry<C>;

export const normalizeCollectionEntries = <C extends CollectionKey>(
  collectionName: C,
  entries: CollectionEntry<C>[],
) => entries.map((entry) => normalizeCollectionEntry(collectionName, entry));
