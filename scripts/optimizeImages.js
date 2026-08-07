import fs from 'node:fs';
import path from 'node:path';
import sharp from 'sharp';

const targetDir = path.resolve('public/images');

function getFiles(dir, filesList = []) {
  const files = fs.readdirSync(dir);
  for (const file of files) {
    const filePath = path.join(dir, file);
    if (fs.statSync(filePath).isDirectory()) {
      getFiles(filePath, filesList);
    } else {
      const ext = path.extname(file).toLowerCase();
      if (['.jpg', '.jpeg', '.png', '.webp'].includes(ext)) {
        filesList.push(filePath);
      }
    }
  }
  return filesList;
}

async function optimizeImages() {
  console.log(`Scanning images in ${targetDir}...`);
  const imageFiles = getFiles(targetDir);
  console.log(`Found ${imageFiles.length} images.`);

  let totalOriginalSize = 0;
  let totalOptimizedSize = 0;
  let processedCount = 0;

  for (const file of imageFiles) {
    const stat = fs.statSync(file);
    const origSize = stat.size;
    totalOriginalSize += origSize;

    const ext = path.extname(file).toLowerCase();
    const tempFile = `${file}.tmp`;

    try {
      let imagePipeline = sharp(file);
      const metadata = await imagePipeline.metadata();

      // Resize if larger than 1920px in width/height
      if ((metadata.width && metadata.width > 1920) || (metadata.height && metadata.height > 1920)) {
        imagePipeline = imagePipeline.resize({
          width: metadata.width && metadata.width > 1920 ? 1920 : undefined,
          height: metadata.height && metadata.height > 1920 ? 1920 : undefined,
          fit: 'inside',
          withoutEnlargement: true
        });
      }

      if (ext === '.jpg' || ext === '.jpeg') {
        imagePipeline = imagePipeline.jpeg({ quality: 82, mozjpeg: true });
      } else if (ext === '.png') {
        imagePipeline = imagePipeline.png({ quality: 82, compressionLevel: 9 });
      } else if (ext === '.webp') {
        imagePipeline = imagePipeline.webp({ quality: 82 });
      }

      await imagePipeline.toFile(tempFile);
      const newStat = fs.statSync(tempFile);

      // Only replace if optimized size is smaller
      if (newStat.size < origSize) {
        fs.renameSync(tempFile, file);
        totalOptimizedSize += newStat.size;
        const savedKb = ((origSize - newStat.size) / 1024).toFixed(1);
        console.log(`✓ Optimized ${path.relative(targetDir, file)}: ${(origSize / 1024).toFixed(1)} KB -> ${(newStat.size / 1024).toFixed(1)} KB (saved ${savedKb} KB)`);
      } else {
        fs.unlinkSync(tempFile);
        totalOptimizedSize += origSize;
        console.log(`- Skipped ${path.relative(targetDir, file)}: already optimal`);
      }
      processedCount++;
    } catch (err) {
      if (fs.existsSync(tempFile)) fs.unlinkSync(tempFile);
      totalOptimizedSize += origSize;
      console.error(`✗ Error processing ${file}:`, err.message);
    }
  }

  const savedTotalMb = ((totalOriginalSize - totalOptimizedSize) / (1024 * 1024)).toFixed(2);
  const origTotalMb = (totalOriginalSize / (1024 * 1024)).toFixed(2);
  const optTotalMb = (totalOptimizedSize / (1024 * 1024)).toFixed(2);

  console.log('\n========================================');
  console.log(`Finished processing ${processedCount} images.`);
  console.log(`Original total size: ${origTotalMb} MB`);
  console.log(`Optimized total size: ${optTotalMb} MB`);
  console.log(`Total saved: ${savedTotalMb} MB (${(((totalOriginalSize - totalOptimizedSize) / totalOriginalSize) * 100).toFixed(1)}%)`);
  console.log('========================================\n');
}

optimizeImages();
