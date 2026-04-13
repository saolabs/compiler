#!/usr/bin/env node

/**
 * Saola Compiler - Test Suite
 * Tests the Python wrapper and integration
 */

const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');
const Compiler = require('./index');
const ConfigManager = require('./config-manager');

class CompilerTests {
    constructor() {
        this.testsPassed = 0;
        this.testsFailed = 0;
        this.errors = [];
    }

    log(message) {
        console.log(`  ${message}`);
    }

    test(name, fn) {
        try {
            fn();
            this.log(`✅ ${name}`);
            this.testsPassed++;
        } catch (error) {
            this.log(`❌ ${name}`);
            this.log(`   Error: ${error.message}`);
            this.testsFailed++;
            this.errors.push({ test: name, error: error.message });
        }
    }

    async run() {
        console.log('\n🧪 Running Saola Compiler Tests\n');
        console.log('Testing Core Functionality:');

        this.testConfigManager();
        this.testPythonCompilerPath();
        this.testFileDiscovery();

        console.log('\n📊 Test Results:');
        console.log(`   Passed: ${this.testsPassed}`);
        console.log(`   Failed: ${this.testsFailed}`);
        console.log(`   Total:  ${this.testsPassed + this.testsFailed}`);

        if (this.testsFailed > 0) {
            console.log('\n❌ Failures:');
            for (const { test, error } of this.errors) {
                console.log(`   - ${test}: ${error}`);
            }
            process.exit(1);
        } else {
            console.log('\n✨ All tests passed!\n');
            process.exit(0);
        }
    }

    /**
     * Test config manager
     */
    testConfigManager() {
        console.log('\n1. Configuration Manager:');

        this.test('ConfigManager is defined', () => {
            if (!ConfigManager) throw new Error('ConfigManager not found');
        });

        this.test('ConfigManager has loadConfig method', () => {
            if (typeof ConfigManager.loadConfig !== 'function') {
                throw new Error('loadConfig method not found');
            }
        });

        this.test('ConfigManager has validateConfig method', () => {
            if (typeof ConfigManager.validateConfig !== 'function') {
                throw new Error('validateConfig method not found');
            }
        });

        // Test with example config
        this.test('Can load example config', () => {
            try {
                const configPath = path.join(__dirname, 'sao.config.example.json');
                if (fs.existsSync(configPath)) {
                    const config = JSON.parse(fs.readFileSync(configPath, 'utf8'));
                    ConfigManager.validateConfig(config);
                }
            } catch (error) {
                throw new Error(`Failed to load example config: ${error.message}`);
            }
        });
    }

    /**
     * Test Python compiler path detection
     */
    testPythonCompilerPath() {
        console.log('\n2. Python Compiler Detection:');

        this.test('Compiler class is defined', () => {
            if (!Compiler) throw new Error('Compiler not found');
        });

        this.test('Compiler has pythonPath property', () => {
            const compiler = new Compiler();
            if (!compiler.pythonPath) {
                throw new Error('pythonPath property not found');
            }
        });

        this.test('Python compiler path exists', () => {
            const compiler = new Compiler();
            const pythonPath = compiler.pythonPath;
            if (!pythonPath) throw new Error('Python path is empty');
            if (!pythonPath.includes('main_compiler.py')) {
                throw new Error('Path does not point to main_compiler.py');
            }
            if (!fs.existsSync(pythonPath)) {
                throw new Error(`Python compiler not found at: ${pythonPath}`);
            }
        });

        this.test('Python 3 is available', (done) => {
            spawn('python3', ['--version'], {
                stdio: ['pipe', 'pipe', 'pipe']
            }).on('close', (code) => {
                if (code !== 0) throw new Error('Python 3 not available');
            }).on('error', () => {
                throw new Error('Python 3 not found in PATH');
            });
        });
    }

    /**
     * Test file discovery
     */
    testFileDiscovery() {
        console.log('\n3. File Discovery:');

        this.test('Compiler has findSaoFiles method', () => {
            const compiler = new Compiler();
            if (typeof compiler.findSaoFiles !== 'function') {
                throw new Error('findSaoFiles method not found');
            }
        });

        this.test('Can find .sao files', () => {
            const compiler = new Compiler();
            const tempDir = path.join(__dirname, '.test-files');
            
            // Create test directory
            if (!fs.existsSync(tempDir)) {
                fs.mkdirSync(tempDir, { recursive: true });
            }

            try {
                // Create test files
                fs.writeFileSync(path.join(tempDir, 'test.sao'), '@useState($count, 0)\n<blade></blade>\n<script></script>');
                fs.writeFileSync(path.join(tempDir, 'other.txt'), 'not a sao file');

                // Find files
                const files = compiler.findSaoFiles(tempDir);
                if (files.length !== 1) {
                    throw new Error(`Expected 1 .sao file, found ${files.length}`);
                }

                if (!files[0].endsWith('.sao')) {
                    throw new Error('Found file is not a .sao file');
                }
            } finally {
                // Cleanup
                try {
                    fs.unlinkSync(path.join(tempDir, 'test.sao'));
                    fs.unlinkSync(path.join(tempDir, 'other.txt'));
                    fs.rmdirSync(tempDir);
                } catch (e) {
                    // Ignore cleanup errors
                }
            }
        });

        this.test('Returns empty array for non-existent directory', () => {
            const compiler = new Compiler();
            const files = compiler.findSaoFiles('/non/existent/path/12345');
            if (!Array.isArray(files)) throw new Error('Result is not an array');
            if (files.length !== 0) throw new Error('Should return empty array for non-existent directory');
        });
    }
}

// Run tests
if (require.main === module) {
    const tests = new CompilerTests();
    tests.run().catch(error => {
        console.error('Test runner error:', error.message);
        process.exit(1);
    });
}

module.exports = CompilerTests;

module.exports = CompilerTests;
