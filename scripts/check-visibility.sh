#!/bin/bash

# 检查Switon框架代码中的可见性问题
# 查找所有使用private的地方
#
# 注意：此脚本仅检查我们自己的代码，不检查vendor目录中的第三方依赖

echo "🔍 检查Switon框架代码中的可见性问题..."
echo "=========================================="

# 检查packages目录 (排除vendor和node_modules等第三方目录)
if [ -d "packages" ]; then
    echo "📁 检查 packages/ 目录..."
    private_files=$(find packages -name "*.php" -not -path "*/vendor/*" -not -path "*/node_modules/*" -exec grep -l "private " {} \; 2>/dev/null)

    if [ -n "$private_files" ]; then
        echo "❌ 发现以下文件使用了 'private' 关键字："
        echo "$private_files" | while read -r file; do
            echo "  🔴 $file"
            grep -n "private " "$file" | sed 's/^/    /'
        done
        echo ""
        echo "💡 提示：请将 'private' 改为 'protected'"
    else
        echo "✅ packages/ 目录中没有使用 'private' 关键字"
    fi
fi

# 检查app-admin目录 (排除vendor和node_modules等第三方目录)
if [ -d "app-admin" ]; then
    echo ""
    echo "📁 检查 app-admin/ 目录..."
    private_files=$(find app-admin -name "*.php" -not -path "*/vendor/*" -not -path "*/node_modules/*" -exec grep -l "private " {} \; 2>/dev/null)

    if [ -n "$private_files" ]; then
        echo "❌ 发现以下文件使用了 'private' 关键字："
        echo "$private_files" | while read -r file; do
            echo "  🔴 $file"
            grep -n "private " "$file" | sed 's/^/    /'
        done
        echo ""
        echo "💡 提示：请将 'private' 改为 'protected'"
    else
        echo "✅ app-admin/ 目录中没有使用 'private' 关键字"
    fi
fi

echo ""
echo "=========================================="
echo "🎯 检查完成！"