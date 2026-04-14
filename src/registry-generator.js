/**
 * Registry Generator - Generate view registry for each context
 * Registry file imports all views and exports a mapping object
 */

const fs = require('fs');
const path = require('path');

class RegistryGenerator {
    /**
     * Generate registry file for a context
     * @param {string} contextName - Context name (admin, web, mobile)
     * @param {Array} viewEntries - Array of view entry objects:
     *   - namingPath: path with namespace for factory name (admin/templates/counter.ts)
     *   - actualPath: actual file path relative to viewsDir (templates/counter.ts or admin/templates/counter.ts)
     * @param {string} outputPath - Output path for registry.js/ts
     * @param {string} viewsDir - Base views directory (for calculating relative imports)
     */
    static generate(contextName, viewEntries, outputPath, viewsDir) {
        const imports = [];
        const exports = [];
        
        // Detect if any view is TypeScript
        const hasTypeScript = viewEntries.some(entry => {
            const p = typeof entry === 'string' ? entry : entry.namingPath;
            return p.endsWith('.ts');
        });
        const registryExt = hasTypeScript ? '.ts' : '.js';
        
        // Update output path extension
        outputPath = outputPath.replace(/\.(js|ts)$/, registryExt);

        viewEntries.forEach(entry => {
            // Support both old format (string) and new format (object)
            const namingPath = typeof entry === 'string' ? entry : entry.namingPath;
            const actualPath = typeof entry === 'string' ? entry : entry.actualPath;
            
            // namingPath format: always includes namespace
            // - "admin/templates/counter.ts", "web/pages/home.ts"
            // Factory function name uses namespace from namingPath
            const factoryName = this._toFactoryName(namingPath);
            const dotPath = this._toDotPath(namingPath);

            // Calculate relative path from registry to view file
            // Use actualPath which reflects real file location
            const registryDir = path.dirname(outputPath);
            const fullViewPath = path.join(viewsDir, actualPath);
            const relativePath = path.relative(registryDir, fullViewPath)
                .replace(/\\/g, '/')
                .replace(/\.(ts|js)$/, '.js'); // Always import .js for runtime

            imports.push(`import ${factoryName} from './${relativePath}';`);
            exports.push(`    '${dotPath}': ${factoryName}`);
        });

        // Add types for TypeScript
        const typeImport = hasTypeScript 
            ? `import type { View } from 'saola';\n\n` 
            : '';
        
        const registryType = hasTypeScript
            ? ': Record<string, (data?: any, systemData?: any) => View>'
            : '';

        const content = `/**
 * Auto-generated View Registry for ${contextName} context
 * Generated at: ${new Date().toISOString()}
 * 
 * This file imports all compiled views and exports them as a registry object.
 * Usage in app.ts:
 * 
 * import registry from './one/${contextName}/registry.js';
 * App.View.registerViews(registry);
 */

${typeImport}${imports.join('\n')}

export const ViewRegistry${registryType} = {
${exports.join(',\n')}
};

export default ViewRegistry;
`;

        // Ensure directory exists
        const dir = path.dirname(outputPath);
        if (!fs.existsSync(dir)) {
            fs.mkdirSync(dir, { recursive: true });
        }

        fs.writeFileSync(outputPath, content, 'utf8');
        console.log(`   ✓ Generated registry: ${path.basename(outputPath)}`);
    }

    /**
     * Convert view path to factory function name (PascalCase)
     * Uses namespace from path, NOT context name
     * Giữ nguyên internal capitals (useState → UseState)
     * @param {string} viewPath - View path with namespace (admin/templates/counter.ts, web/pages/home.ts)
     * @returns {string} Factory name (AdminTemplatesCounter, WebPagesHome)
     */
    static _toFactoryName(viewPath) {
        // Remove extension first
        const pathWithoutExt = viewPath.replace(/\.(ts|js)$/, '');
        
        // Split path: admin/templates/counter -> ['admin', 'templates', 'counter']
        const parts = pathWithoutExt.split('/');
        
        // Convert all parts to PascalCase, preserve internal capitals
        return parts.map(part => {
            // Convert part to PascalCase (handle hyphens/underscores)
            // Preserve internal capitals: useState → UseState
            return part.split(/[-_]/)
                .map(word => word.charAt(0).toUpperCase() + word.slice(1))
                .join('');
        }).join('');
    }

    /**
     * Convert file path to dot notation view path
     * "admin/templates/counter.ts" -> "admin.templates.counter"
     */
    static _toDotPath(viewPath) {
        const parts = viewPath
            .replace(/\.(ts|js)$/, '')
            .split(/[\/\\]/)
            .filter(p => p);
        
        return parts.join('.');
    }
}

module.exports = { RegistryGenerator };
