#!/bin/bash

# 检查Switon框架源码中的可见性问题
# 查找源码中使用private的地方 (排除测试文件和第三方依赖)

echo "🔍 检查Switon框架源码中的可见性问题..."
echo "=========================================="
echo "📋 只检查源码文件，排除测试文件和第三方依赖"
echo ""

# 定义要检查的源码目录 (排除vendor目录)
SOURCE_DIRS=("packages/core/src" "packages/di/src" "packages/id/src" "packages/jwt/src" "packages/crypto/src" "packages/inspector/src" "packages/redis/src" "packages/invoker/src" "packages/db/src" "packages/log/src" "packages/orm/src" "packages/event/src" "app-admin/app")

total_private_found=0

for dir in "${SOURCE_DIRS[@]}"; do
    if [ -d "$dir" ]; then
        echo "📁 检查 $dir/ 目录..."

        # 排除vendor、node_modules、tests等目录
        private_files=$(find $dir -name "*.php" \
            -not -path "*/vendor/*" \
            -not -path "*/node_modules/*" \
            -not -path "*/tests/*" \
            -not -path "*/Fixtures/*" \
            -exec grep -l "private " {} \; 2>/dev/null)

        if [ -n "$private_files" ]; then
            echo "❌ 发现以下源码文件使用了 'private' 关键字："
            file_count=0
            echo "$private_files" | while read -r file; do
                echo "  🔴 $file"
                private_lines=$(grep -n "private " "$file" | wc -l)
                echo "    📊 $private_lines 个 private 声明"
                file_count=$((file_count + 1))
            done
            echo "  📈 共 $file_count 个文件"
            echo ""
            total_private_found=$((total_private_found + file_count))
        else
            echo "✅ $dir/ 目录中没有使用 'private' 关键字"
            echo ""
        fi
    fi
done

echo "=========================================="
if [ $total_private_found -gt 0 ]; then
    echo "⚠️  发现 $total_private_found 个源码文件使用了 'private' 关键字"
    echo "💡 提示：请将 'private' 改为 'protected' 以符合框架规范"
else
    echo "🎉 所有源码文件都符合可见性规范！"
fi
echo "🎯 检查完成！"
