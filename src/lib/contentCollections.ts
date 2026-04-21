import { getCollection, getEntry, type CollectionEntry, type CollectionKey } from "astro:content";
import {
  normalizeCollectionEntries,
  normalizeCollectionEntry,
} from "@/lib/utils/contentNormalizer";

export const getNormalizedCollection = async <C extends CollectionKey>(
  collectionName: C,
  filter?: Parameters<typeof getCollection>[1],
): Promise<CollectionEntry<C>[]> => {
  const entries = await getCollection(collectionName, filter);
  return normalizeCollectionEntries(collectionName, entries);
};

export const getNormalizedEntry = async <C extends CollectionKey>(
  collectionName: C,
  documentId: string,
): Promise<CollectionEntry<C> | null> => {
  const entry = await getEntry(collectionName, documentId);
  return entry ? normalizeCollectionEntry(collectionName, entry) : null;
};
