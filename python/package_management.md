If you mean **update `requirements.txt` with the packages currently installed in your Python virtual environment**, the standard way is:

### 1. Activate your virtual environment

Linux/macOS:

```bash
source venv/bin/activate
```

Windows:

```powershell
venv\Scripts\activate
```

### 2. Update `requirements.txt`

```bash
pip freeze > requirements.txt
```

This replaces the existing file with **all packages installed in the active environment**, including their exact versions.

Example:

```txt
fastapi==0.116.1
uvicorn==0.35.0
sqlalchemy==2.0.43
pydantic==2.11.7
```

### 3. If you only installed a new package

For example:

```bash
pip install redis
```

Then:

```bash
pip freeze > requirements.txt
```

### 4. If you want to update packages first

```bash
pip install --upgrade -r requirements.txt
```

Then regenerate:

```bash
pip freeze > requirements.txt
```

### Important

If this is a **production project**, I recommend keeping a clean `requirements.txt` containing only your project's direct dependencies rather than blindly using `pip freeze`, because `pip freeze` also includes transitive dependencies.

For example:

```txt
fastapi==0.116.1
uvicorn[standard]==0.35.0
sqlalchemy==2.0.43
psycopg[binary]==3.2.9
redis==6.4.0
python-jose==3.5.0
```

Then install everything with:

```bash
pip install -r requirements.txt
```

**For your FastAPI/backend projects,** a good workflow is:

```bash
# activate environment
source venv/bin/activate

# install/change dependencies
pip install <package>

# regenerate requirements
pip freeze > requirements.txt

# verify
pip check
```

If you show me your current `requirements.txt`, I can also tell you **which packages should actually be there and which are unnecessary/transitive dependencies**.
