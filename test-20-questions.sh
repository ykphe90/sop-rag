#!/bin/bash

# Week 3 Day 7 - RAG v1 效果评估
# 20 道测试题，5 大类别
# 用法：bash test-20-questions.sh 2>&1 | tee results.txt

BASE="http://localhost:8000/api/ask"
PASS=0
TOTAL=0

run_test() {
  local category="$1"
  local question="$2"

  TOTAL=$((TOTAL + 1))
  echo ""
  echo "[$TOTAL] [$category] $question"
  echo "---"

  result=$(curl -s -X POST "$BASE" \
    -H "Content-Type: application/json" \
    -d "{\"question\": \"$question\", \"top_k\": 5}")

  answer=$(echo "$result" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('answer','ERROR'))" 2>/dev/null)
  top_source=$(echo "$result" | python3 -c "import sys,json; d=json.load(sys.stdin); s=d.get('sources',[]); print(s[0]['file_code']+' · '+s[0]['section']+' ('+str(s[0]['similarity'])+')') if s else print('无来源')" 2>/dev/null)
  tokens=$(echo "$result" | python3 -c "import sys,json; d=json.load(sys.stdin); t=d.get('tokens_used',{}); print(str(t.get('total_tokens','?'))+'tok')" 2>/dev/null)

  echo "答案: $answer"
  echo "来源: $top_source | $tokens"
}

echo "=============================="
echo " RAG v1 效果评估 - 20 题测试"
echo "=============================="

# ── 类别 1：食品安全（SOP-001）──────────────────
run_test "食品安全" "砧板颜色分类怎么用？红色蓝色绿色分别代表什么"
run_test "食品安全" "食品危险温度带是几度到几度"
run_test "食品安全" "冷冻食品的接收标准是什么"
run_test "食品安全" "员工上岗前需要检查哪些个人卫生要求"

# ── 类别 2：厨房操作（SOP-002）──────────────────
run_test "厨房操作" "开店前需要做哪些设备检查"
run_test "厨房操作" "燃气泄漏应该怎么检测"
run_test "厨房操作" "工作日午餐需要准备多少份食材"
run_test "厨房操作" "厨房关店流程是什么"

# ── 类别 3：顾客服务（SOP-003）──────────────────
run_test "顾客服务" "顾客投诉应该怎么处理"
run_test "顾客服务" "外卖订单出错了怎么办"
run_test "顾客服务" "顾客等待超过多久需要主动告知"
run_test "顾客服务" "如何处理顾客给差评"

# ── 类别 4：紧急事件（SOP-004）──────────────────
run_test "紧急事件" "发现有人晕倒应该怎么处理"
run_test "紧急事件" "火灾疏散时可以用电梯吗"
run_test "紧急事件" "停电时需要采取哪些措施"
run_test "紧急事件" "遇到食物中毒事件应该怎么上报"

# ── 类别 5：员工管理（SOP-005）──────────────────
run_test "员工管理" "员工年假有多少天"
run_test "员工管理" "公共假期加班费怎么计算"
run_test "员工管理" "新员工试用期要多久"
run_test "员工管理" "员工被解雇有哪些原因"

echo ""
echo "=============================="
echo " 测试完成，共 $TOTAL 题"
echo " 请手动检查答案质量，填写评分表"
echo "=============================="
