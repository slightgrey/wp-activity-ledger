/**
 * Shows the server's validation message for a field, if any.
 *
 * @param {object} props
 * @param {object} props.errors Errors by field (from error.data.errors).
 * @param {string} props.field  Field key.
 */
export default function FieldError({ errors, field }) {
  if (!errors?.[field]) return null;

  return <p className="text-xs text-destructive">{errors[field]}</p>;
}
