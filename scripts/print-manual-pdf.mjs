#!/usr/bin/env node
/**
 * Imprime HTML local a PDF con pie fijo + paginación (API Chrome vía Puppeteer).
 * Uso: CHROME_EXECUTABLE=/ruta/a/Google\ Chrome node scripts/print-manual-pdf.mjs <abs.html> <out.pdf> "pie de página"
 */
import puppeteer from "puppeteer-core";

function escapeHtml(s) {
  return String(s)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

const htmlPath = process.argv[2];
const pdfPath = process.argv[3];
const footerLine = process.argv[4] || "Manual de usuario | por Digixop";
const chromePath = process.env.CHROME_EXECUTABLE || "";

if (!htmlPath || !pdfPath) {
  console.error("Uso: CHROME_EXECUTABLE=/... node print-manual-pdf.mjs <html> <pdf> \"pie\"");
  process.exit(1);
}
if (!chromePath) {
  console.error("ERROR: defina CHROME_EXECUTABLE (ruta al binario de Chrome o Chromium).");
  process.exit(1);
}

const fileUrl = htmlPath.startsWith("file:") ? htmlPath : `file://${htmlPath}`;
const safeFooter = escapeHtml(footerLine);

const footerTemplate = `<div style="box-sizing:border-box;width:100%;padding:6px 14mm 4px;font-size:9px;line-height:1.35;color:#5f6368;text-align:center;font-family:system-ui,-apple-system,sans-serif;border-top:1px solid #2170b0;">
  <span>${safeFooter}</span>
  <span style="color:#bdc1c6"> · </span>
  <span style="color:#2170b0;font-weight:600">pág. <span class="pageNumber"></span> / <span class="totalPages"></span></span>
</div>`;

const browser = await puppeteer.launch({
  executablePath: chromePath,
  headless: true,
  args: ["--disable-gpu", "--no-first-run"],
});

try {
  const page = await browser.newPage();
  await page.goto(fileUrl, { waitUntil: "networkidle0", timeout: 120_000 });
  await page.pdf({
    path: pdfPath,
    format: "A4",
    printBackground: true,
    displayHeaderFooter: true,
    headerTemplate: "<div></div>",
    footerTemplate,
    margin: { top: "16mm", bottom: "24mm", left: "17mm", right: "17mm" },
  });
} finally {
  await browser.close();
}
