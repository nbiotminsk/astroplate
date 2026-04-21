import { resolveDate } from "@/lib/utils/contentNormalizer";

// sort by date
export const sortByDate = (array: any[]) => {
  const sortedArray = array.sort(
    (a: any, b: any) =>
      resolveDate(b.data.date).valueOf() - resolveDate(a.data.date).valueOf(),
  );
  return sortedArray;
};

// sort product by weight
export const sortByWeight = (array: any[]) => {
  const withWeight = array.filter(
    (item: { data: { weight: any } }) => item.data.weight,
  );
  const withoutWeight = array.filter(
    (item: { data: { weight: any } }) => !item.data.weight,
  );
  const sortedWeightedArray = withWeight.sort(
    (a: { data: { weight: number } }, b: { data: { weight: number } }) =>
      a.data.weight - b.data.weight,
  );
  const sortedArray = [...new Set([...sortedWeightedArray, ...withoutWeight])];
  return sortedArray;
};
