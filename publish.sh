#!/bin/bash

# Saola Compiler - Build and Publish Script

echo "🔧 Testing Saola Compiler..."
npm test

if [ $? -eq 0 ]; then
    echo "✅ Tests passed!"

    echo "📦 Publishing to npm..."
    # Uncomment the next line when ready to publish
    # npm publish

    echo "🎉 Saola Compiler published successfully!"
else
    echo "❌ Tests failed!"
    exit 1
fi