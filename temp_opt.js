const sharp = require("sharp");
const fs = require("fs");
const path = require("path");

const imagesDir = "public/images";
const files = fs.readdirSync(imagesDir);

async function optimizeImages() {
  for (const file of files) {
    if (file.toLowerCase().endsWith(".png")) {
      const filePath = path.join(imagesDir, file);
      const stats = fs.statSync(filePath);

      // Only process files larger than 500KB
      if (stats.size > 500 * 1024) {
        console.log(
          `Optimizing ${file} (${(stats.size / 1024 / 1024).toFixed(2)} MB)...`,
        );

        try {
          const image = sharp(filePath);
          const metadata = await image.metadata();

          let pipeline = image;

          // Resize if too large
          if (metadata.width > 1280) {
            pipeline = pipeline.resize(1280, null, {
              withoutEnlargement: true,
            });
          }

          // Compress PNG
          const buffer = await pipeline
            .png({ quality: 80, palette: true })
            .toBuffer();

          fs.writeFileSync(filePath, buffer);

          const newStats = fs.statSync(filePath);
          console.log(
            `  Done: ${(newStats.size / 1024 / 1024).toFixed(2)} MB (${((1 - newStats.size / stats.size) * 100).toFixed(1)}% reduction)`,
          );
        } catch (err) {
          console.error(`  Error optimizing ${file}:`, err);
        }
      }
    }
  }
}

optimizeImages();
