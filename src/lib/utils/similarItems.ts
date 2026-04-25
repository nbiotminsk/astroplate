// similar products
const similarItems = (currentItem: any, allItems: any[]) => {
  let categories: string[] = currentItem.data.categories || [];
  let tags: string[] = currentItem.data.tags || [];

  // Filter out the current item
  const otherItems = allItems.filter((item: any) => item.id !== currentItem.id);

  // Filter by categories
  const filterByCategories = otherItems.filter((item: any) =>
    categories.find((category) => item.data.categories?.includes(category)),
  );

  // Filter by tags
  const filterByTags = otherItems.filter((item: any) =>
    tags.find((tag) => item.data.tags?.includes(tag)),
  );

  // Merged unique similar items
  let mergedItems = [...new Set([...filterByCategories, ...filterByTags])];

  // If we have less than 3 similar items, fill with other latest items
  if (mergedItems.length < 3) {
    const mergedIds = new Set(mergedItems.map((i) => i.id));
    const latestItems = otherItems
      .filter((item) => !mergedIds.has(item.id))
      .sort(
        (a, b) =>
          new Date(b.data.date).getTime() - new Date(a.data.date).getTime(),
      );

    mergedItems = [...mergedItems, ...latestItems];
  }

  return mergedItems;
};

export default similarItems;
