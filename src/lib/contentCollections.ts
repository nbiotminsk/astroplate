import {
  getCollection,
  getEntry,
  type CollectionEntry,
  type CollectionKey,
} from "astro:content";
import {
  normalizeCollectionEntries,
  normalizeCollectionEntry,
} from "@/lib/utils/contentNormalizer";

type CollectionFilter<C extends CollectionKey> = (
  entry: CollectionEntry<C>,
) => boolean;

export const getNormalizedCollection = async <C extends CollectionKey>(
  collectionName: C,
  filter?: CollectionFilter<C>,
): Promise<CollectionEntry<C>[]> => {
  const entries = await getCollection(
    collectionName,
    filter as Parameters<typeof getCollection>[1],
  );
  return normalizeCollectionEntries(
    collectionName,
    entries as CollectionEntry<C>[],
  );
};

export const getNormalizedEntry = async <C extends CollectionKey>(
  collectionName: C,
  documentId: string,
): Promise<CollectionEntry<C> | null> => {
  const entry = await getEntry(collectionName, documentId);
  return entry
    ? normalizeCollectionEntry(collectionName, entry as CollectionEntry<C>)
    : null;
};
