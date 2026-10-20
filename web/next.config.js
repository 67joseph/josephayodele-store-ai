/** Static export: upload the generated `out/` folder to any web host. */
module.exports = {
  output: 'export',
  // Set when hosting in a subfolder, e.g. NEXT_PUBLIC_BASE_PATH=/mysite
  basePath: process.env.NEXT_PUBLIC_BASE_PATH || '',
  trailingSlash: true,
  images: { unoptimized: true },
};
