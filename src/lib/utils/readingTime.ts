// content reading
const readingTime = (content: string): string => {
  const wps = 275 / 60;

  let images = 0;
  const words = content.split(" ").filter((word) => {
    if (word.includes("<img")) images++;
    return /\w/.test(word);
  }).length;

  const imageAdjust = images * 4;
  const imageSecs =
    images <= 10 ? (images * (25 - images)) / 2 : 75 + (images - 10) * 3;

  const minutes = Math.ceil(((words - imageAdjust) / wps + imageSecs) / 60);
  const label = minutes < 2 ? "Min read" : "Mins read";

  return `${minutes < 10 ? "0" + minutes : minutes} ${label}`;
};

export default readingTime;
