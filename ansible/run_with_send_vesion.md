# ansible-playbook deploy_parking.yml -e "version=1.2.0"

# ansible-playbook deploy.yml -e "version=1.0.0"



---
- name: Deploy Parking API
  hosts: parking_servers
  become: true

  vars:
    # ---------------------------------------------------------
    # Application
    # ---------------------------------------------------------

    app_dir: /opt/apps/parkings/backend
    app_name: parking-api

    image: "0788644687/parking-api:{{ version }}"

    health_url: "http://127.0.0.1:8000/api/v1/health"


    # ---------------------------------------------------------
    # PostgreSQL
    # ---------------------------------------------------------

    db_container: postgres
    db_name: parking_db
    db_user: parking_user

    backup_dir: /data/backups/parkings/oltp/before_{{ version }}

    # Temporary database used to verify that the backup
    # can actually be restored.
    restore_test_db: "parking_backup_test_{{ deployment_timestamp }}"


    # ---------------------------------------------------------
    # Deployment logging
    # ---------------------------------------------------------

    deployment_log_dir: /var/log/deployments/parking


  tasks:

    # =========================================================
    # 1. Prepare directories
    # =========================================================

    - name: Create backup directory
      ansible.builtin.file:
        path: "{{ backup_dir }}"
        state: directory
        owner: root
        group: root
        mode: "0700"

    - name: Create deployment log directory
      ansible.builtin.file:
        path: "{{ deployment_log_dir }}"
        state: directory
        owner: root
        group: root
        mode: "0750"


    # =========================================================
    # 2. Deployment information
    # =========================================================

    - name: Create deployment timestamp
      ansible.builtin.set_fact:
        deployment_timestamp: "{{ ansible_date_time.iso8601_basic_short }}"

    - name: Create deployment log file
      ansible.builtin.set_fact:
        deployment_log: "{{ deployment_log_dir }}/deploy_{{ deployment_timestamp }}.log"


    # =========================================================
    # 3. Check PostgreSQL
    # =========================================================

    - name: Check PostgreSQL container
      ansible.builtin.command:
        cmd: docker inspect -f "{{ '{{' }}.State.Running{{ '}}' }}" {{ db_container }}
      register: postgres_status
      changed_when: false

    - name: Stop deployment if PostgreSQL is not running
      ansible.builtin.fail:
        msg: "PostgreSQL container '{{ db_container }}' is not running."
      when: postgres_status.stdout != "true"


    # =========================================================
    # 4. Check PostgreSQL network
    # =========================================================

    - name: Check postgres_backend network
      ansible.builtin.command:
        cmd: docker network inspect postgres_backend
      register: postgres_network
      changed_when: false


    # =========================================================
    # 5. Create PostgreSQL backup
    # =========================================================

    - name: Set backup file path
      ansible.builtin.set_fact:
        backup_file: "{{ backup_dir }}/{{ db_name }}_{{ deployment_timestamp }}.dump"

    - name: Backup production database
      ansible.builtin.shell:
        cmd: >
          docker exec {{ db_container }}
          pg_dump
          -U {{ db_user }}
          -d {{ db_name }}
          -Fc
          > {{ backup_file }}
      args:
        executable: /bin/bash
      register: backup_result
      changed_when: true

    - name: Check backup exists
      ansible.builtin.stat:
        path: "{{ backup_file }}"
      register: backup_file_stat

    - name: Fail if backup was not created
      ansible.builtin.fail:
        msg: "Database backup was not created: {{ backup_file }}"
      when: not backup_file_stat.stat.exists

    - name: Fail if backup is too small
      ansible.builtin.fail:
        msg: >-
          Database backup appears to be invalid or empty.
          File size: {{ backup_file_stat.stat.size | default(0) }} bytes.
      when: backup_file_stat.stat.size | int < 1024


    # =========================================================
    # 6. Validate backup archive
    # =========================================================

    - name: Validate PostgreSQL backup archive
      ansible.builtin.shell:
        cmd: >
          docker exec -i {{ db_container }}
          pg_restore --list
          < {{ backup_file }}
      args:
        executable: /bin/bash
      register: backup_validation
      changed_when: false

    - name: Fail if backup archive validation failed
      ansible.builtin.fail:
        msg: "PostgreSQL backup archive validation failed."
      when: backup_validation.rc != 0


    
    
    # =========================================================
    # 7. ACTUAL RESTORE TEST
    # =========================================================

    - name: Create temporary restore-test database name
      ansible.builtin.set_fact:
        restore_test_db: "parking_backup_test_{{ deployment_timestamp | lower }}"


    - name: Test PostgreSQL backup restore
      block:

        - name: Create temporary restore-test database
          ansible.builtin.command:
            cmd: >
              docker exec {{ db_container }}
              createdb
              -U postgres
              {{ restore_test_db }}
          changed_when: true


        - name: Restore backup into temporary database
          ansible.builtin.shell:
            cmd: >
              docker exec -i {{ db_container }}
              pg_restore
              -U postgres
              -d {{ restore_test_db }}
              --exit-on-error
              < {{ backup_file }}
          args:
            executable: /bin/bash
          changed_when: true


        - name: Verify restored database is accessible
          ansible.builtin.command:
            cmd: >
              docker exec {{ db_container }}
              psql
              -U postgres
              -d {{ restore_test_db }}
              -tAc "SELECT 1"
          register: restore_database_check
          changed_when: false


        - name: Fail if restored database is not accessible
          ansible.builtin.fail:
            msg: "Backup restore test failed."
          when: restore_database_check.stdout | trim != "1"


      always:

        - name: Remove temporary restore-test database
          ansible.builtin.command:
            cmd: >
              docker exec {{ db_container }}
              dropdb
              -U postgres
              --if-exists
              {{ restore_test_db }}
          changed_when: true
          failed_when: false


        - name: Confirm restore-test database was removed
          ansible.builtin.command:
            cmd: >
              docker exec {{ db_container }}
              psql
              -U postgres
              -d postgres
              -tAc "SELECT 1 FROM pg_database WHERE datname = '{{ restore_test_db }}'"
          register: restore_db_removed
          changed_when: false
          failed_when: false


        - name: Fail if restore-test database was not removed
          ansible.builtin.fail:
            msg: "Temporary restore-test database was not removed: {{ restore_test_db }}"
          when: restore_db_removed.stdout | trim != ""




    # =========================================================
    # 8. Deployment start log
    # =========================================================

    - name: Write deployment start log
      ansible.builtin.shell:
        cmd: |
          {
            echo "========================================="
            echo "Parking API Deployment"
            echo "========================================="
            echo "Time: {{ deployment_timestamp }}"
            echo "Version: {{ version }}"
            echo "Image: {{ image }}"
            echo "Database: {{ db_name }}"
            echo "Backup: {{ backup_file }}"
            echo "Backup archive validation: SUCCESS"
            echo "Backup restore test: SUCCESS"
            echo "========================================="
          } | tee -a {{ deployment_log }}
      args:
        executable: /bin/bash


    # =========================================================
    # 9. Pull exact application image
    # =========================================================

    - name: Pull application image
      ansible.builtin.command:
        cmd: docker pull {{ image }}
      register: image_pull
      changed_when: true


    # =========================================================
    # 10. Run Alembic migration
    #
    # Uses the exact same image that will run in production.
    # parking-migration service is no longer required.
    # =========================================================

    - name: Run Alembic migrations
      ansible.builtin.command:
        cmd: >
          docker compose
          --env-file .env
          run
          --rm
          -T
          parking-api
          alembic upgrade head
      args:
        chdir: "{{ app_dir }}"
      environment:
        IMAGE: "{{ image }}"
      register: migration_result


    # =========================================================
    # 11. Verify Alembic version
    # =========================================================

    - name: Check current Alembic version
      ansible.builtin.command:
        cmd: >
          docker compose
          --env-file .env
          run
          --rm
          -T
          parking-api
          alembic current
      args:
        chdir: "{{ app_dir }}"
      environment:
        IMAGE: "{{ image }}"
      register: migration_version
      changed_when: false


    # =========================================================
    # 12. Deploy application
    # =========================================================

    - name: Start parking application
      ansible.builtin.command:
        cmd: >
          docker compose
          --env-file .env
          up
          -d
          --no-deps
          parking-api
      args:
        chdir: "{{ app_dir }}"
      environment:
        IMAGE: "{{ image }}"
      register: application_deployment


    # =========================================================
    # 13. Health check
    # =========================================================

    - name: Wait for API health check
      ansible.builtin.uri:
        url: "{{ health_url }}"
        status_code: 200
        timeout: 5
      register: health_check
      retries: 12
      delay: 5


    # =========================================================
    # 14. Collect container status
    # =========================================================

    - name: Get application status
      ansible.builtin.command:
        cmd: docker ps --filter name={{ app_name }}
      register: container_status
      changed_when: false


    # =========================================================
    # 15. Collect application logs
    # =========================================================

    - name: Collect application logs
      ansible.builtin.command:
        cmd: docker logs --tail=200 {{ app_name }}
      register: application_logs
      changed_when: false
      failed_when: false


    # =========================================================
    # 16. Save deployment result
    # =========================================================

    - name: Save deployment result
      ansible.builtin.shell:
        cmd: |
          {
            echo ""
            echo "========================================="
            echo "DEPLOYMENT SUCCESS"
            echo "========================================="
            echo "Time: {{ deployment_timestamp }}"
            echo "Version: {{ version }}"
            echo "Image: {{ image }}"
            echo ""
            echo "Backup:"
            echo "{{ backup_file }}"
            echo "Backup archive validation: SUCCESS"
            echo "Backup restore test: SUCCESS"
            echo ""
            echo "Alembic:"
            echo "{{ migration_version.stdout }}"
            echo ""
            echo "Container:"
            echo "{{ container_status.stdout }}"
            echo ""
            echo "Application logs:"
            echo "{{ application_logs.stdout }}"
            echo ""
            echo "========================================="
          } >> {{ deployment_log }}
      args:
        executable: /bin/bash


    # =========================================================
    # 17. Final summary
    # =========================================================

    - name: Deployment summary
      ansible.builtin.debug:
        msg:
          - "Deployment successful"
          - "Version: {{ version }}"
          - "Image: {{ image }}"
          - "Backup: {{ backup_file }}"
          - "Backup restore test: SUCCESS"
          - "Migration: {{ migration_version.stdout | trim }}"
          - "Health check: HTTP 200"
          - "Log: {{ deployment_log }}"

