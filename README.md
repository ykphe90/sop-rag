# SOP Intelligent Q&A System

> A production-grade **RAG (Retrieval-Augmented Generation)** system built from scratch for restaurant operations — answering staff questions in Chinese or English based on internal Standard Operating Procedures.

**Tech Stack:** Laravel 13 · PostgreSQL + pgvector · OpenAI API · Cohere Rerank · PHP

---

## Features

- **Semantic Search** — OpenAI `text-embedding-3-small` (1536-dim vectors) stored in PostgreSQL via pgvector
- **Hybrid Search** — Combines vector similarity + PostgreSQL full-text search, merged with RRF (Reciprocal Rank Fusion) scoring
- **Reranking** — Cohere cross-encoder re-scores candidates for higher source accuracy
- **Conversation Memory** — Multi-turn follow-up questions supported (last 3 turns in context)
- **Trilingual** — Toggle between Chinese (中文), English, and Bahasa Malaysia (BM); cross-lingual queries work out of the box
- **Source Citations** — Every answer cites the SOP document and section it came from
- **Evaluated** — 20-question benchmark; 75% strict accuracy on v1, improved with Hybrid Search + Reranking

---

## RAG Pipeline

```
User Question
     │
     ▼
[1] Embed          text-embedding-3-small → 1536-dim vector
     │
     ▼
[2] Hybrid Search  pgvector cosine (70%) + PostgreSQL tsvector (30%)
                   → top 10 candidates via RRF scoring
     │
     ▼
[3] Rerank         Cohere rerank-v3.5 cross-encoder
                   → reorder by true relevance, keep top 5
     │
     ▼
[4] Augment        Inject SOP chunks + conversation history into prompt
     │
     ▼
[5] Generate       GPT-4o-mini → answer with source citations
```

---

## Project Structure

```
sop-rag/
├── app/
│   ├── Console/Commands/
│   │   └── IndexSopDocuments.php   # Ingestion pipeline (chunk → embed → store)
│   ├── Http/Controllers/
│   │   ├── AskController.php       # POST /api/ask — full RAG pipeline
│   │   ├── SearchController.php    # POST /api/search, /api/search-hybrid
│   │   └── EmbeddingController.php # POST /api/embed, /api/similarity
│   └── Services/
│       ├── EmbeddingService.php    # OpenAI embeddings
│       ├── ChunkService.php        # Document chunking strategies
│       ├── HybridSearchService.php # Vector + full-text search with RRF
│       ├── RerankService.php       # Cohere reranking
│       ├── LlmService.php          # GPT-4o-mini chat completions
│       └── PromptBuilder.php       # System prompt + context assembly
├── database/migrations/
│   ├── ..._create_sop_documents_table.php    # pgvector(1536) + HNSW index
│   └── ..._add_fulltext_to_sop_documents.php # tsvector + GIN index + trigger
├── public/
│   ├── chat.html                   # Single-page chat UI
│   └── SOP-00{1-5}-*.md            # Source SOP documents (5 files)
└── routes/api.php
```

---

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/ask` | Full RAG pipeline — question → answer + sources |
| `POST` | `/api/search` | Vector-only semantic search |
| `POST` | `/api/search-hybrid` | Hybrid search with RRF scoring |
| `POST` | `/api/embed` | Get embedding vector for any text |
| `POST` | `/api/similarity` | Cosine similarity between two texts |

**Example request:**
```json
POST /api/ask
{
  "question": "What temperature should chicken be stored at?",
  "lang": "en",
  "top_k": 5,
  "history": [
    {"role": "user", "content": "What are the food safety rules?"},
    {"role": "assistant", "content": "According to SOP-001..."}
  ]
}
```

**Example response:**
```json
{
  "answer": "Chicken should be stored at 0–4°C (per SOP-001, Section 4.2).",
  "sources": [
    {
      "file_code": "SOP-001",
      "section": "4.2 储存原则（FIFO）",
      "similarity": 0.71,
      "rerank_score": 0.89,
      "search_type": "hybrid"
    }
  ],
  "model": "gpt-4o-mini-2024-07-18",
  "tokens_used": { "total_tokens": 742 }
}
```

---

## Setup

### Prerequisites
- PHP 8.2+, Composer
- PostgreSQL 14+ with **pgvector** extension
- OpenAI API key
- Cohere API key (free tier: 1,000 reranks/month)

### Install

```bash
git clone https://github.com/YOUR_USERNAME/sop-rag.git
cd sop-rag

composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env` — fill in DB credentials, `OPENAI_API_KEY`, `COHERE_API_KEY`.

```bash
# Create database
createdb sop_rag
psql sop_rag -c "CREATE EXTENSION IF NOT EXISTS vector;"

# Run migrations
php artisan migrate

# Index all 5 SOP documents
php artisan sop:index

# Start server
php artisan serve
# → Open http://localhost:8000/chat.html
```

### Re-index from scratch
```bash
php artisan sop:index --fresh
```

---

## Evaluation

20-question benchmark across 5 categories (food safety, kitchen ops, customer service, emergencies, HR):

| Version | Strict Accuracy | Notes |
|---------|----------------|-------|
| v1 — vector search only | 75% (15/20) | 3 failed, 2 partial |
| v2 — hybrid + rerank | ~85% | Source confusion fixed; low-similarity recall improved |

**Key finding:** Similarity > 0.6 → near 100% accuracy. Hybrid Search rescued low-similarity queries (keyword-specific terms like "关店"). Reranking fixed source attribution errors where two SOPs covered overlapping topics.

---

## Key Design Decisions

**Heading-based chunking** — SOP documents use clear section headers; splitting by `##`/`###` preserves semantic boundaries better than fixed-size chunks.

**RRF over weighted average** — Rank-based fusion is robust to score scale differences between cosine similarity and text rank scores.

**Cohere Rerank over a second LLM call** — Faster, cheaper, purpose-built. Cross-encoders evaluate query+document jointly, which is fundamentally more accurate for relevance ranking than bi-encoder similarity.

**History in frontend, not backend** — Stateless backend = simpler architecture. Client sends last 3 turns; server needs no session storage.

---

## Built By

**YK Phe** — AI Integration Engineer  
[LinkedIn](https://www.linkedin.com/in/yeong-kiang-phe-67960a119) · y.k.phe90@gmail.com
