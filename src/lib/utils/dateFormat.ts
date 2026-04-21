import { format } from "date-fns";
import { ru } from "date-fns/locale";
import { resolveDate } from "@/lib/utils/contentNormalizer";

const dateFormat = (
  date?: Date | string | number | null,
  pattern: string = "dd MMM, yyyy",
): string => {
  const dateObj = resolveDate(date);
  const output = format(dateObj, pattern, { locale: ru });
  return output;
};

export default dateFormat;
