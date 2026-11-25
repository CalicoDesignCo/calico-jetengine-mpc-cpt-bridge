import fs from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath,pathToFileURL} from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const {runCLI}=await import(process.env.CALICO_PLAYGROUND_CLI ? pathToFileURL(process.env.CALICO_PLAYGROUND_CLI).href : '@wp-playground/cli');
const zip=process.env.CALICO_TEST_ZIP || path.join(root,'dist/calico-jetengine-mpc-cpt-bridge.zip');
const server=await runCLI({command:'server',php:'8.3',wp:'6.9',port:9455});
try {
 const p=server.playground;await fs.mkdir(path.join(root,'test-results'),{recursive:true});
 await p.writeFile('/tmp/plugin.zip',await fs.readFile(zip));
 const unpack=await p.run({code:"<?php $z=new ZipArchive();if($z->open('/tmp/plugin.zip')!==true)throw new Exception('Bad ZIP');$z->extractTo('/wordpress/wp-content/plugins');$z->close();"});if(unpack.exitCode)throw Error(unpack.errors);
 for(const file of ['integration.php','legacy-smoke.php','updater.php']){
  await p.writeFile('/tmp/'+file,await fs.readFile(path.join(root,'tests',file)));
  const r=await p.run({scriptPath:'/tmp/'+file});console.log(r.text);await fs.writeFile(path.join(root,'test-results',file+'.txt'),r.text+'\n'+r.errors);if(r.exitCode||r.errors)throw Error(r.errors||'PHP failure');
 }
 // Isolated older-version fixture carries this updater; no fake public release is published.
 const plugin='/wordpress/wp-content/plugins/calico-jetengine-mpc-cpt-bridge/calico-jetengine-mpc-cpt-bridge.php';
 const current=await p.readFileAsText(plugin);await p.writeFile(plugin,current.replaceAll('2.6.0','2.5.0'));
 await p.writeFile('/tmp/upgrade.php',await fs.readFile(path.join(root,'tests/upgrade.php')));
 const upgrade=await p.run({scriptPath:'/tmp/upgrade.php'});console.log(upgrade.text);await fs.writeFile(path.join(root,'test-results/upgrade.txt'),upgrade.text+'\n'+upgrade.errors);if(upgrade.exitCode||upgrade.errors)throw Error(upgrade.errors||'Upgrade failure');
}catch(e){console.error(e.message);process.exitCode=1;}finally{await server[Symbol.asyncDispose]();}
