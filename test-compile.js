/**
 * Test compilation for examples/sao/
 * Generates blade and JS output into examples/blade/ and examples/js/
 */
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const Compiler = require('./src/index');
const SaolaPreprocessor = require('./src/preprocessor');

const compiler = new Compiler();
const preprocessor = new SaolaPreprocessor();

const examplesDir = path.resolve(__dirname, 'examples/sao');
const bladeOutputDir = path.resolve(__dirname, 'examples/blade');
const jsOutputDir = path.resolve(__dirname, 'examples/js');

// Ensure output dirs exist
if (!fs.existsSync(bladeOutputDir)) fs.mkdirSync(bladeOutputDir, { recursive: true });
if (!fs.existsSync(jsOutputDir)) fs.mkdirSync(jsOutputDir, { recursive: true });

const pythonPath = 'python3';
const sao2bladeScript = path.resolve(__dirname, 'src/sao2blade/cli.py');
const sao2jsScript = path.resolve(__dirname, 'src/sao2js/cli.py');

console.log('🚀 Starting Test Compile\n');

const files = fs.readdirSync(examplesDir).filter(f => f.endsWith('.sao'));

for (const file of files) {
    console.log(`\n📄 Compiling ${file}...`);
    try {
        const saoPath = path.join(examplesDir, file);
        const nameNoExt = path.basename(file, '.sao');
        
        const content = fs.readFileSync(saoPath, 'utf-8');
        const parts = compiler.parseSaoFile(content, saoPath);
        
        // 1. Run Preprocessor to get PHP-compatible context
        const bladeParts = preprocessor.preprocess(parts);
        
        // 2. Prepare content for sao2blade
        let bladeSource = '';
        if (bladeParts.declarations && bladeParts.declarations.length > 0) {
            bladeSource += bladeParts.declarations.join('\n') + '\n\n';
        }
        bladeSource += bladeParts.bladeWithSSR || bladeParts.blade;
        
        const tempBladeInput = path.join(examplesDir, `.${nameNoExt}.temp.blade.sao`);
        fs.writeFileSync(tempBladeInput, bladeSource);

        // 3. Prepare content for sao2js
        // sao2js needs script/style tags to extract metadata
        let jsSource = '';
        if (bladeParts.declarations && bladeParts.declarations.length > 0) {
            jsSource += bladeParts.declarations.join('\n') + '\n\n';
        }
        
        const scriptTags = parts.cleanedContent.match(/<script[^>]*>[\s\S]*?<\/script>/gi) || [];
        const styleTags = parts.cleanedContent.match(/<style[^>]*>[\s\S]*?<\/style>/gi) || [];
        const linkStyleTags = parts.cleanedContent.match(/<link[^>]*rel=["']stylesheet["'][^>]*>/gi) || [];
        
        if (scriptTags.length > 0 || styleTags.length > 0 || linkStyleTags.length > 0) {
            jsSource += [...scriptTags, ...styleTags, ...linkStyleTags].join('\n') + '\n\n';
        }
        jsSource += bladeParts.blade;

        const tempJsInput = path.join(examplesDir, `.${nameNoExt}.temp.js.sao`);
        fs.writeFileSync(tempJsInput, jsSource);

        // 4. Detect script language
        const scriptTagMatch = parts.cleanedContent.match(/<script[^>]*>/i);
        const scriptLang = scriptTagMatch ? (scriptTagMatch[0].match(/lang=["']([^"']+)["']/i)?.[1] || 'js') : 'js';
        const isTypeScript = scriptLang.toLowerCase() === 'ts' || scriptLang.toLowerCase() === 'typescript';
        const jsExtension = isTypeScript ? 'ts' : 'js';
        
        // 5. Output paths
        const finalBladePath = path.join(bladeOutputDir, `${nameNoExt}.blade.php`);
        const finalJsPath = path.join(jsOutputDir, `${nameNoExt}.${jsExtension}`);
        
        const tempBladeOutput = path.join(examplesDir, `.${nameNoExt}.temp.blade.out`);
        const tempJsOutput = path.join(examplesDir, `.${nameNoExt}.temp.js.out`);
        
        // --- Compile Blade ---
        const bladeRes = spawnSync(pythonPath, [sao2bladeScript, tempBladeInput, tempBladeOutput], { encoding: 'utf-8' });
        if (bladeRes.status !== 0) {
            console.error(`❌ Blade Compile Error for ${file}: ${bladeRes.stderr || bladeRes.stdout}`);
        } else {
            const outputBladeContent = fs.readFileSync(tempBladeOutput, 'utf-8');
            fs.writeFileSync(finalBladePath, outputBladeContent);
            console.log(`   ✅ Wrote Blade: examples/blade/${nameNoExt}.blade.php`);
        }
        
        // --- Compile JS/TS ---
        // cli.py <input> <output> [name] [path] [factory]
        const compName = compiler.generateComponentName(nameNoExt);
        const viewPath = `examples.${nameNoExt}`;
        const factory = compiler.generateFactoryFunctionName(nameNoExt);
        
        const jsRes = spawnSync(pythonPath, [sao2jsScript, tempJsInput, tempJsOutput, compName, viewPath, factory], { encoding: 'utf-8' });
        if (jsRes.status !== 0) {
            console.error(`❌ JS Compile Error for ${file}: ${jsRes.stderr || jsRes.stdout}`);
        } else {
            const outputJsContent = fs.readFileSync(tempJsOutput, 'utf-8');
            fs.writeFileSync(finalJsPath, outputJsContent);
            console.log(`   ✅ Wrote TS/JS: examples/js/${nameNoExt}.${jsExtension}`);
        }
        
        // Cleanup temp files
        const toCleanup = [tempBladeInput, tempJsInput, tempBladeOutput, tempJsOutput];
        for (const f of toCleanup) {
            if (fs.existsSync(f)) fs.unlinkSync(f);
        }
        
    } catch (err) {
        console.error(`❌ Error processing ${file}: ${err.message}`);
    }
}

console.log('\n✨ Build complete!');
