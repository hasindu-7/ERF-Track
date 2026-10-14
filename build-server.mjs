import { build } from 'esbuild';
await build({entryPoints:['lib/server-core.ts'],outfile:'engine.mjs',bundle:true,platform:'node',format:'esm',target:'node22'});
