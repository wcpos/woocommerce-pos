@coderabbitai Please generate release notes by analyzing the commits between the previous tag and this release.

**Instructions:**
1. Analyze each commit message and the actual code changes
2. Group changes into appropriate categories
3. Write concise, user-friendly descriptions (not developer-focused)
4. Focus on what changed from the user's perspective

**For patch releases (x.x.X), use this format:**

## 🛠️ Changelog

### 🐞 Bug Fixes:
- [brief user-friendly description]

### ⚡ Enhancements:
- [brief user-friendly description]

---

**For minor releases (x.X.0) with new features, use this format:**

## 🛠️ Changelog

### 🚀 New Features:
- [brief user-friendly description]

### 🐞 Bug Fixes:
- [brief user-friendly description]

### ⚡ Enhancements:
- [brief user-friendly description]

---

**For major releases (X.0.0), include an introduction:**

[2-3 sentence summary of what this major release brings]

## 🛠️ Changelog

### 🚀 New Features:
- [brief user-friendly description]

### 🐞 Bug Fixes:
- [brief user-friendly description]

### ⚡ Enhancements:
- [brief user-friendly description]

---

**Notes:**
- Omit empty sections
- Combine related commits into single entries
- Skip internal/CI changes unless they affect users
- Keep descriptions to one line each
