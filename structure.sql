CREATE EXTENSION IF NOT EXISTS plpgsql WITH SCHEMA pg_catalog;

CREATE SCHEMA system;

CREATE TABLE system.deployed_scripts
(
  id serial,
  filename text NOT NULL,
  inserted_datetime timestamp with time zone NOT NULL DEFAULT now(),
  CONSTRAINT deployed_scripts_pkey PRIMARY KEY (id)
);

CREATE UNIQUE INDEX deployed_scripts_filename_ukey ON system.deployed_scripts (lower(filename) ASC NULLS LAST);

---

CREATE FUNCTION system.deploy_script(in_filename text, in_dependence text[] DEFAULT NULL)
  RETURNS void AS
$BODY$
DECLARE
  v_dependence text;
BEGIN
  IF EXISTS (SELECT 1 FROM system.deployed_scripts WHERE lower(filename) = lower(in_filename)) THEN
    RAISE EXCEPTION USING MESSAGE = format('Script "%s" is already deployed.', in_filename);
  END IF;

  IF in_dependence IS NOT NULL THEN
    FOREACH v_dependence IN ARRAY in_dependence LOOP
      IF NOT EXISTS (SELECT 1 FROM system.deployed_scripts WHERE lower(filename) = lower(v_dependence)) THEN
        RAISE EXCEPTION USING MESSAGE = format('Script "%s" needs script "%s" to be deployed.', in_filename, v_dependence);
      END IF;
    END LOOP;
  END IF;

  INSERT INTO system.deployed_scripts(filename) VALUES (in_filename);
END;
$BODY$
  LANGUAGE plpgsql VOLATILE;

---

CREATE FUNCTION system.show_deploy_warning(in_message text)
  RETURNS void AS
$BODY$
BEGIN
  RAISE EXCEPTION USING MESSAGE = in_message, HINT = 'Comment this line to continue.';
END
$BODY$
  LANGUAGE plpgsql VOLATILE;
