import { glob } from "astro/loaders";
import { defineCollection } from "astro:content";
import { z } from "astro/zod";
import { getBuildDate } from "@/lib/utils/buildTimestamp";

const safeDate = z.preprocess(
  (value) => (value === "" || value == null ? undefined : value),
  z.coerce.date().default(() => getBuildDate()),
);

const commonFields = {
  title: z.string(),
  description: z.string().default(""),
  meta_title: z.string().optional(),
  // Empty or missing dates fall back to the build timestamp.
  date: safeDate,
  image: z.string().optional(),
  draft: z.boolean().default(false),
};

// Post collection schema
const blogCollection = defineCollection({
  loader: glob({ pattern: "**/*.{md,mdx}", base: "src/content/blog" }),
  schema: z.object({
    title: z.string(),
    meta_title: z.string().optional(),
    description: z.string().optional(),
    date: safeDate,
    image: z.string().optional(),
    author: z.string().default("Admin"),
    // Use factory functions for mutable array defaults (Zod 4 best practice)
    categories: z.array(z.string()).default(() => ["others"]),
    tags: z.array(z.string()).default(() => ["others"]),
    draft: z.boolean().default(false),
  }),
});

// Pages collection schema
const pagesCollection = defineCollection({
  loader: glob({ pattern: "**/*.{md,mdx}", base: "src/content/pages" }),
  schema: z.object({
    ...commonFields,
  }),
});

// contact collection schema
const contactCollection = defineCollection({
  loader: glob({ pattern: "**/*.{md,mdx}", base: "src/content/contact" }),
  schema: z.object({
    ...commonFields,
  }),
});

// info collection schema
const infoCollection = defineCollection({
  loader: glob({ pattern: "**/*.{md,mdx}", base: "src/content/info" }),
  schema: z.object({
    img: z.string().optional(),
    title: z.string(),
    description: z.string().optional(),
    draft: z.boolean().optional(),
  }),
});

// Homepage collection schema
const homepageCollection = defineCollection({
  loader: glob({ pattern: "**/-*.{md,mdx}", base: "src/content/homepage" }),
  schema: z.looseObject({
    banner: z.object({
      title: z.string(),
      content: z.string(),
      image: z.string(),
      button: z.object({
        enable: z.boolean(),
        label: z.string(),
        link: z.string(),
      }),
    }),
    features: z.array(
      z.object({
        title: z.string(),
        image: z.string(),
        content: z.string(),
        bulletpoints: z.array(z.string()),
        button: z.object({
          enable: z.boolean(),
          label: z.string(),
          link: z.string(),
        }),
      }),
    ),
  }),
});

// Store collection schema (Sitepins-friendly fields)
const storeCollection = defineCollection({
  loader: glob({ pattern: "**/*.{md,mdx}", base: "src/content/store" }),
  schema: z.looseObject({
    title: z.string(),
    category: z.string().default("others"),
    price: z.union([z.string(), z.number()]).default("0"),
    price_currency: z.union([z.string(), z.number()]).default("BYN"),
    img: z.string().optional(),
    image: z.string().optional(),
    brand: z.string().optional(),
    review: z.object({
      rating: z.number().default(5),
      count: z.number().default(0),
    }).default(() => ({ rating: 5, count: 0 })),
    aggregateRating: z.object({
      ratingValue: z.number().default(5),
      reviewCount: z.number().default(0),
      bestRating: z.number().default(5),
    }).default(() => ({ ratingValue: 5, reviewCount: 0, bestRating: 5 })),
    availability: z.boolean().default(true),
    shippingDetails: z.object({
      shippingType: z.string().default("Delivery"),
      delivery: z.string().optional(),
      weight: z.number().optional(),
      length: z.number().optional(),
      width: z.number().optional(),
      height: z.number().optional(),
    }).optional(),
    hasMerchantReturnPolicy: z.object({
      applicableCountry: z.string().optional(),
      returnPolicyCategory: z.string().default("MerchantReturnsAllowed"),
      obligationDuration: z.string().optional(),
      returnPeriod: z.string().optional(),
      returnShippingCost: z.string().optional(),
    }).optional(),
    short_description: z.string().optional(),
    description: z.string().optional(),
    features: z.array(z.string()).optional(),
    draft: z.boolean().default(false),
    date: safeDate,
  }),
});

// Export collections
export const collections = {
  // Pages
  homepage: homepageCollection,
  blog: blogCollection,
  pages: pagesCollection,
  contact: contactCollection,
  info: infoCollection,
  store: storeCollection,
};
