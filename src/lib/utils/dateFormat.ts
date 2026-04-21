import { format } from "date-fns";
import { ru } from "date-fns/locale";

export const resolveDate = (date?: Date | string | null): Date => {
  if (date instanceof Date && !Number.isNaN(date.valueOf())) {
    return date;
  }

  if (typeof date === "string" || typeof date === "number") {
    const parsedDate = new Date(date);
    if (!Number.isNaN(parsedDate.valueOf())) {
      return parsedDate;
    }
  }

  // Fall back to the build timestamp when the content record has no valid date.
  return new Date();
};

const dateFormat = (
  date?: Date | string | null,
  pattern: string = "dd MMM, yyyy",
): string => {
  const dateObj = resolveDate(date);
  const output = format(dateObj, pattern, { locale: ru });
  return output;
};

export default dateFormat;
