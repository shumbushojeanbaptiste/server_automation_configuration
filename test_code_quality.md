📘 Backend Testing & Code Quality Setup (Jest + Supertest + SonarQube)

## 1. Overview

This project uses:

- Jest: unit testing and test runner
- Supertest: professional API testing for Express endpoints
- MongoDB + MySQL: backend databases
- SonarQube: static code quality + security analysis
- GitHub Actions: CI/CD automation (optional)

## 2. Goal (What to test)

Ensure:

1. API endpoints function correctly
2. Business logic returns expected results
3. CRUD flows are stable
4. Authentication/authorization are safe
5. Regression is prevented via CI

## 3. Project test layout

```
backend/
tests/
  account.test.js
  auth.test.js
  health.test.js
  setup.js
```

## 4. Jest configuration

`jest.config.js`:

```js
module.exports = {
  testEnvironment: 'node',
  testMatch: ['**/tests/**/*.test.js'],
  collectCoverage: true,
  coverageDirectory: 'coverage',
  setupFilesAfterEnv: ['<rootDir>/tests/setup.js'],
  detectOpenHandles: true,
};
```

## 5. Install dependencies

```bash
npm install --save-dev jest supertest
npm install express
```

## 6. Supertest API test example (professional style)

`tests/auth.test.js`:

```js
const request = require('supertest');
const app = require('../backend/app'); // Express app

describe('Auth API', () => {
  test('POST /api/accounts/login missing body returns 400', async () => {
    const response = await request(app)
      .post('/api/accounts/login')
      .send({});

    expect(response.statusCode).toBe(400);
    expect(response.body).toHaveProperty('error');
  });

  test('POST /api/accounts/login wrong credentials returns 401', async () => {
    const response = await request(app)
      .post('/api/accounts/login')
      .send({ username: 'wrong', password: 'invalid' });

    expect(response.statusCode).toBe(401);
  });

  test('POST /api/accounts/login valid credentials returns 200 and token', async () => {
    const response = await request(app)
      .post('/api/accounts/login')
      .send({ username: 'validUser', password: 'validPass' });

    expect(response.statusCode).toBe(200);
    expect(response.body).toHaveProperty('token');
  });
});
```

## 7. Run tests

- `npm test` (all tests)
- `npm test -- --runInBand` (single process)
- `npm test -- --coverage` (coverage report)

## 8. Coverage meaning

- 🟢 Green: well covered
- 🟡 Yellow: partially covered
- 🔴 Red: no tests

`🔴` means missing tests (not always bug). Add tests to cover critical flows.

## 9. Database handling in tests

### Current (less ideal)

- tests use real MySQL and MongoDB
- data may be shared and flaky

### Recommended (professional)

- create a dedicated test database per run
- use in-memory Mongo (`mongodb-memory-server`)
- use Jest mocking for unit tests to avoid DB dependency

`tests/setup.js`:

```js
const { disconnectMongo } = require('../backend/config/mongo');

afterAll(async () => {
  await disconnectMongo();
  await new Promise((resolve) => setTimeout(resolve, 500));
});
```

## 10. General testing best practices

- validate input before DB logic
- return correct HTTP status codes
- keep controllers small and testable
- isolate unit tests from external dependencies
- use test data builders / factories
- clean test state between tests (`beforeEach`, `afterEach`)
- enforce contract tests for public APIs

## 11. SonarQube integration

SonarQube checks:

- bugs
- vulnerabilities
- code smells
- duplicate code
- coverage

### Install Sonar scanner

```bash
npm install --save-dev sonar-scanner
```

### Add `sonar-project.properties`

```
sonar.projectKey=video-platform-backend
sonar.projectName=Video Platform Backend
sonar.sources=backend
sonar.tests=tests
sonar.javascript.lcov.reportPaths=coverage/lcov.info
sonar.sourceEncoding=UTF-8
```

### GitHub secrets

- `SONAR_TOKEN`: token
- `SONAR_HOST_URL`: e.g., `http://your-sonar-server`

### GitHub Actions sample

```yaml
name: Code Quality

on:
  push:
    branches: [main]

jobs:
  sonar:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Install dependencies
        run: npm install
      - name: Run tests
        run: npm test -- --coverage
      - name: SonarQube Scan
        uses: SonarSource/sonarqube-scan-action@v6
        env:
          SONAR_TOKEN: ${{ secrets.SONAR_TOKEN }}
          SONAR_HOST_URL: ${{ secrets.SONAR_HOST_URL }}
```

## 12. Targets for production quality

- Coverage: 70%+
- Duplications: < 5%
- Security issues: 0
- Bugs: 0

---

This guide is now a clear, step-by-step test plan for everything, with professional emphasis on Supertest API automation and code quality workflow.