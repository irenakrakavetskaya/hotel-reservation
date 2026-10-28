'use client';

import { useRouter } from 'next/navigation';
import { useId, useState } from 'react';
import { adminRequest } from '../../lib/admin-client';
import {
  emptyHotel, emptyRoom, emptyRoomType, hotelBody, hotelFields, roomBody, roomFields, roomTypeBody, roomTypeFields,
} from '../../lib/admin-forms';
import type { RoomType } from '../../lib/api';

export type FieldDef = {
  name: string;
  label: string;
  kind: 'text' | 'textarea' | 'number' | 'select';
  required?: boolean;
  hint?: string;
  options?: { value: string; label: string }[];
  step?: string;
};

export type FormValues = Record<string, string>;

type AdminFormProps = {
  resource: 'hotel' | 'roomType' | 'room';
  roomTypes?: RoomType[];
  initialValues?: FormValues;
  method: 'POST' | 'PUT';
  endpoint: string;
  successHref: string;
  submitLabel: string;
};

export default function AdminForm({ resource, roomTypes = [], initialValues, method, endpoint, successHref, submitLabel }: AdminFormProps) {
  const router = useRouter();
  const id = useId();
  const fields = resource === 'hotel' ? hotelFields : resource === 'roomType' ? roomTypeFields : roomFields(roomTypes);
  const buildBody = resource === 'hotel' ? hotelBody : resource === 'roomType' ? roomTypeBody : roomBody;
  const [values, setValues] = useState<FormValues>(
    initialValues ?? (resource === 'hotel' ? emptyHotel : resource === 'roomType' ? emptyRoomType : emptyRoom(roomTypes)),
  );
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (isSubmitting) return;

    const missing: Record<string, string> = {};
    for (const field of fields) {
      if (field.required && (values[field.name] ?? '').trim() === '') missing[field.name] = `Enter ${field.label.toLowerCase()}.`;
    }
    setErrors(missing);
    setFormError(null);
    if (Object.keys(missing).length > 0) {
      setFormError('Please fix the highlighted fields.');
      return;
    }

    setIsSubmitting(true);
    const result = await adminRequest(method, endpoint, buildBody(values));
    setIsSubmitting(false);

    if (!result.ok) {
      if (result.status === 401) {
        router.push(`/login?next=${encodeURIComponent(successHref)}`);
        return;
      }
      setErrors(result.fieldErrors);
      setFormError(result.message);
      return;
    }

    router.push(successHref);
    router.refresh();
  }

  return (
    <form className="auth-form admin-form" onSubmit={submit} noValidate>
      {fields.map((field) => {
        const inputId = `${id}-${field.name}`;
        const error = errors[field.name];
        const common = {
          id: inputId,
          name: field.name,
          value: values[field.name] ?? '',
          'aria-invalid': error ? true : undefined,
          'aria-describedby': error ? `${inputId}-error` : undefined,
          onChange: (event: React.ChangeEvent<HTMLInputElement & HTMLTextAreaElement & HTMLSelectElement>) => setValues({ ...values, [field.name]: event.target.value }),
        };
        return (
          <label key={field.name} htmlFor={inputId}>
            {field.label}{field.required ? ' *' : ''}
            {field.kind === 'textarea' && <textarea rows={4} {...common} />}
            {field.kind === 'select' && (
              <select {...common}>
                {field.options?.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
              </select>
            )}
            {(field.kind === 'text' || field.kind === 'number') && <input type={field.kind} step={field.step} {...common} />}
            {field.hint && !error && <span className="field-hint">{field.hint}</span>}
            {error && <span id={`${inputId}-error`} className="field-error" role="alert">{error}</span>}
          </label>
        );
      })}
      {formError && <p className="booking-error" role="alert">{formError}</p>}
      <button type="submit" disabled={isSubmitting}>{isSubmitting ? 'Saving...' : submitLabel}</button>
    </form>
  );
}
